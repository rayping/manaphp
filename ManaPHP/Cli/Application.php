<?php

namespace ManaPHP\Cli;

use ManaPHP\Logger\LogCategorizable;

/**
 * Class ManaPHP\Cli\Application
 *
 * @package application
 *
 * @property \ManaPHP\Cli\HandlerInterface $cliHandler
 */
class Application extends \ManaPHP\Application implements LogCategorizable
{
    /**
     * @return string
     */
    public function categorizeLog()
    {
        return 'cli';
    }

    /**
     * Application constructor.
     *
     * @param \ManaPHP\Loader      $loader
     * @param \ManaPHP\DiInterface $di
     */
    public function __construct($loader, $di = null)
    {
        parent::__construct($loader, $di);

        if ($appDir = $this->alias->get('@app')) {
            if (is_dir("$appDir/Cli")) {
                $this->alias->set('@cli', "$appDir/Cli/Controllers");
                if ($appNamespace = $this->alias->get('@ns.app')) {
                    $this->alias->set('@ns.cli', "$appNamespace/Cli/Controllers");
                }
            } elseif (($calledClass = get_called_class()) !== __CLASS__) {
                $this->alias->set('@cli', "$appDir/Controllers");
                $this->alias->set('@ns.cli', substr($calledClass, 0, strrpos($calledClass, '\\') + 1) . 'Controllers');
            }
        }
    }

    /**
     * @return array
     */
    public function coreComponents()
    {
        return [
            'cliHandler' => 'ManaPHP\Cli\Handler',
            'console' => 'ManaPHP\Cli\Console',
            'arguments' => 'ManaPHP\Cli\Arguments',
            'commandInvoker' => 'ManaPHP\Cli\Command\Invoker',
            'errorHandler' => 'ManaPHP\Cli\ErrorHandler'
        ];
    }

    public function registerServices()
    {
        $this->configure->bootstraps = array_diff($this->configure->bootstraps, ['debugger']);

        parent::registerServices();
    }

    public function main()
    {
        $this->dotenv->load();
        $this->configure->loadFile();

        $this->registerServices();

        $this->logger->addAppender(['class' => 'file', 'file' => '@data/logger/console.log'], 'console');
        $this->logger->info(['command line: :cmd', 'cmd' => basename($GLOBALS['argv'][0]) . ' ' . implode(' ', array_slice($GLOBALS['argv'], 1))]);

        try {
            exit($this->cliHandler->handle());
        } /** @noinspection PhpUndefinedClassInspection */
        catch (\Exception $e) {
            $this->errorHandler->handle($e);
        } catch (\Error $e) {
            $this->errorHandler->handle($e);
        }
    }
}