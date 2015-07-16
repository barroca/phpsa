<?php
/**
 * @author Patsura Dmitry https://github.com/ovr <talk@dmtry.me>
 */

namespace PHPSA\Command;

use PHPSA\Application;
use PHPSA\Context;
use PHPSA\Definition\ClassDefinition;
use PHPSA\Definition\ClassMethod;
use PHPSA\Definition\FunctionDefinition;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Exception;
use FilesystemIterator;
use PhpParser\Node;
use PhpParser\Parser;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CheckCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('check')
            ->setDescription('SPA')
            ->addOption('blame', null, InputOption::VALUE_OPTIONAL, 'Git blame author for bad code ;)', false)
            ->addArgument('path', InputArgument::OPTIONAL, 'Path to check file or directory', '.');
    }

    /**
     * @var ClassDefinition[]
     */
    protected $classes = array();

    /**
     * @var ClassDefinition[]
     */
    protected $functions = array();

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('');

        if (extension_loaded('xdebug')) {
            $output->writeln('<error>It is highly recommended to disable the XDebug extension before invoking this command.</error>');
        }

        $parser = new Parser(new \PhpParser\Lexer\Emulative(
            array(
                'usedAttributes' => array(
                    'comments',
                    'startLine',
                    'endLine',
                    'startTokenPos',
                    'endTokenPos'
                )
            )
        ));

        /** @var Application $application */
        $application = $this->getApplication();
        $context = new Context($output, $application);

        /**
         * Store option's in application's configuration
         */
        $application->getConfiguration()->setValue('blame', $input->getOption('blame'));

        $path = $input->getArgument('path');
        if (is_dir($path)) {
            $directoryIterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
            );
            $output->writeln('Scanning directory <info>' . $path . '</info>');

            $count = 0;

            /** @var SplFileInfo $file */
            foreach ($directoryIterator as $file) {
                if ($file->getExtension() != 'php') {
                    continue;
                }

                $context->debug($file->getPathname());
                $count++;
            }

            $output->writeln(sprintf('found <info>%d files</info>', $count));

            if ($count > 100) {
                $output->writeln('<comment>Caution: You are trying to scan a lot of files; this might be slow. For bigger libraries, consider setting up a dedicated platform or using owl-ci.dmtry.me.</comment>');
            }

            $output->writeln('');

            /** @var SplFileInfo $file */
            foreach ($directoryIterator as $file) {
                if ($file->getExtension() != 'php') {
                    continue;
                }

                $this->parserFile($file->getPathname(), $parser, $context);
            }
        } elseif (is_file($path)) {
            $this->parserFile($path, $parser, $context);
        }


        /**
         * Step 2 Recursive check ...
         */

        /**
         * @var $class ClassDefinition
         */
        foreach ($this->classes as $class) {
            $class->compile($context);
        }

        $output->writeln('');


        $output->writeln('Memory usage: ' . $this->getMemoryUsage(false) . ' (peak: ' . $this->getMemoryUsage(true) . ') MB');
    }

    /**
     * @param boolean $type
     * @return float
     */
    protected function getMemoryUsage($type)
    {
        return round(memory_get_usage($type) / 1024 / 1024, 2);
    }

    /**
     * @param string $filepath
     * @param Parser $parser
     * @param Context $context
     */
    protected function parserFile($filepath, Parser $parser, Context $context)
    {
        try {
            if (!is_readable($filepath)) {
                throw new RuntimeException('File ' . $filepath . ' is not readable');
            }

            $code = file_get_contents($filepath);
            $stmts = $parser->parse($code);

            $namespace = null;

            /**
             * Step 1 Precompile
             */
            if ($stmts[0] instanceof Node\Stmt\Namespace_) {
                $namespace = implode('\\', $stmts[0]->name->parts);
                $stmts = $stmts[0]->stmts;
            }

            foreach ($stmts as $statement) {
                if ($statement instanceof Node\Stmt\Class_) {
                    $definition = new ClassDefinition($statement->name);
                    $definition->setFilepath($filepath);

                    if ($namespace) {
                        $definition->setNamespace($namespace);
                    }

                    foreach ($statement->stmts as $stmt) {
                        if ($stmt instanceof Node\Stmt\ClassMethod) {
                            $method = new ClassMethod($stmt->name, $stmt->stmts, $stmt->type, $stmt);

                            $definition->addMethod($method);
                        } elseif ($stmt instanceof Node\Stmt\Property) {
                            $definition->addProperty($stmt);
                        } elseif ($stmt instanceof Node\Stmt\ClassConst) {
                            $definition->addConst($stmt);
                        }
                    }

                    $this->classes[] = $definition;
                } elseif ($statement instanceof Node\Stmt\Function_) {
                    $definition = new FunctionDefinition($statement->name);
                    $definition->setFilepath($filepath);

                    if ($namespace) {
                        $definition->setNamespace($namespace);
                    }

                    $this->functions[] = $definition;
                }
            }

            $context->clear();
        } catch (\PhpParser\Error $e) {
            $context->sytaxError($e, $filepath);
        } catch (Exception $e) {
            $context->output->writeln("<error>{$e->getMessage()}</error>");
        }
    }
}
