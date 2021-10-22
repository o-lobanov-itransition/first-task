<?php

namespace App\Command;

use App\Domain\ImportRule\CostFrom5OrStockFrom10Rule;
use App\Domain\ImportRule\CostLessOrEqual1000Rule;
use App\Entity\ProductData;
use App\Service\ImportRuleEngine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Serializer\Encoder\CsvEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;

class ImportProductsCommand extends Command
{
    private const STATUS_SUCCESSFUL = 'successful';
    private const STATUS_SKIPPED = 'skipped';

    protected static $defaultName = 'app:import-products';

    private array $rows;
    private EntityManagerInterface $em;
    private ImportRuleEngine $importRuleEngine;

    public function __construct(EntityManagerInterface $em, ImportRuleEngine $importRuleEngine)
    {
        $this->rows = [];
        $this->em = $em;
        $this->importRuleEngine = $importRuleEngine;

        $this->importRuleEngine
            ->addRule(new CostFrom5OrStockFrom10Rule())
            ->addRule(new CostLessOrEqual1000Rule())
        ;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('This command allows you to import products from a CSV file')
            ->addArgument('filename', InputArgument::REQUIRED, 'The filename of the import CSV file.')
            ->addOption(
                'test',
                null,
                InputOption::VALUE_OPTIONAL,
                'Is it test (without saving data)?',
                false
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $fileName = $input->getArgument('filename');
        $isTest = null === $input->getOption('test');

        if (true === $isTest) {
            $output->writeln('Warning: this command is in test mode!');
        }

        $serializer = new Serializer([new ObjectNormalizer()], [new CsvEncoder()]);
        $this->rows = $serializer->decode(file_get_contents($fileName), 'csv');

        $qty = count($this->rows);
        $output->writeln("Processing {$qty} row(s):");

        $validator = Validation::createValidator();
        $constraints = $this->getConstraints();

        $output->write('[');
        foreach ($this->rows as $i => $row) {
            $errors = $validator->validate($row, $constraints);

            if (0 === count($errors)) {
                $product = $this->createProductData($row);

                $ruleErrors = $this->importRuleEngine->validate($product);
                if (0 !== count($ruleErrors)) {
                    $this->setRowStatus($i, self::STATUS_SKIPPED);
                    $this->setRowError($i, $ruleErrors);
                    continue;
                }

                try {
                    if (false === $isTest) {
                        $this->em->persist($product);
                        $this->em->flush();
                    }

                    $this->setRowStatus($i, self::STATUS_SUCCESSFUL);
                } catch (\Exception $exception) {
                    $this->setRowStatus($i, self::STATUS_SKIPPED);
                    $this->setRowError($i, $exception->getMessage());
                }
            } else {
                $this->setRowStatus($i, self::STATUS_SKIPPED);
                $this->setRowError($i, $errors);
            }
            $output->write('*');
        }
        $output->writeln(']');

        $qty = count($this->rows);
        $output->writeln("Processed {$qty} row(s)");
        $successfulQty = $this->getRowsCountByStatus(self::STATUS_SUCCESSFUL);
        $output->writeln("Successfully {$successfulQty} row(s)");
        $output->writeln('==========================');

        $skippedQty = $this->getRowsCountByStatus(self::STATUS_SKIPPED);
        if ($skippedQty > 0) {
            $output->writeln("Skipped {$skippedQty} row(s):");
            foreach ($this->rows as $i => $row) {
                if (self::STATUS_SKIPPED !== $row['status']) {
                    continue;
                }

                $nRow = $i + 2;
                $code = $row['Product Code'] ?? 'Unknown';
                $error = $row['error'] ?? '-';
                $output->writeln("Line {$nRow} with the code '{$code}': {$error}");
            }

            $output->writeln('==========================');
        }

        return Command::SUCCESS;
    }

    private function getConstraints(): Constraint
    {
        return new Assert\Collection([
            'Product Name' => [new Assert\Required(), new Assert\NotBlank()],
            'Product Description' => [new Assert\Required(), new Assert\NotBlank()],
            'Product Code' => [new Assert\Required(), new Assert\NotBlank()],
            'Stock' => new Assert\PositiveOrZero(),
            'Cost in GBP' => new Assert\AtLeastOneOf([new Assert\Blank(), new Assert\PositiveOrZero()]),
            'Discontinued' => new Assert\AtLeastOneOf([new Assert\Blank(), new Assert\Choice(['yes'])]),
        ]);
    }

    private function setRowStatus(int $index, string $status): void
    {
        $this->rows[$index]['status'] = $status;
    }

    private function setRowError(int $index, mixed $error): void
    {
        $this->rows[$index]['error'] = is_array($error) ? implode("\n", $error) : (string) $error;
    }

    private function getRowsCountByStatus(string $status): int
    {
        $count = 0;

        foreach ($this->rows as $row) {
            if ($row['status'] === $status) {
                ++$count;
            }
        }

        return $count;
    }

    private function createProductData(array $row): ProductData
    {
        $name = $row['Product Name'];
        $desc = $row['Product Description'];
        $code = $row['Product Code'];
        $stock = (int) ($row['Stock'] ?? 0);
        $price = isset($row['Cost in GBP']) ? (float) $row['Cost in GBP'] : null;
        $discontinued = $row['Discontinued'] ?? null;

        $product = (new ProductData())
            ->setProductName($name)
            ->setProductDesc($desc)
            ->setProductCode($code)
            ->setStock($stock)
            ->setPrice($price);

        /*
         * Any stock item marked as discontinued will be imported,
         * but will have the discontinued date set as the current date.
         */
        if ('yes' === $discontinued) {
            $product->setDiscontinued(new \DateTime());
        }

        return $product;
    }
}
