<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\WebServerBundle\Command;

use Symfony\Bridge\Monolog\Formatter\ConsoleFormatter;
use Symfony\Bridge\Monolog\Handler\ConsoleHandler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

/**
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 */
class ServerLogCommand extends Command
{
    private static $bgColor = array('black', 'blue', 'cyan', 'green', 'magenta', 'red', 'white', 'yellow');

    private $el;
    private $handler;

    public function isEnabled()
    {
        if (!class_exists(ConsoleFormatter::class)) {
            return false;
        }

        return parent::isEnabled();
    }

    protected function configure()
    {
        $this->setName('server:log');

        if (!class_exists(ConsoleFormatter::class)) {
            return;
        }

        $this
            ->setDescription('Start a log server that displays logs in real time')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'The server host', '0:9911')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'The line format', ConsoleFormatter::SIMPLE_FORMAT)
            ->addOption('date-format', null, InputOption::VALUE_REQUIRED, 'The date format', ConsoleFormatter::SIMPLE_DATE)
            ->addOption('filter', null, InputOption::VALUE_REQUIRED, 'An expression to filter log. Example: "level > 200 or channel in [\'app\', \'doctrine\']"')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $filter = $input->getOption('filter');
        if ($filter) {
            if (!class_exists(ExpressionLanguage::class)) {
                throw new \LogicException('Package "symfony/expression-language" is required to use the "filter" option.');
            }
            $this->el = new ExpressionLanguage();
        }

        $this->handler = new ConsoleHandler($output);

        $this->handler->setFormatter(new ConsoleFormatter(array(
            'format' => str_replace('\n', "\n", $input->getOption('format')),
            'date_format' => $input->getOption('date-format'),
            'colors' => $output->isDecorated(),
            'multiline' => OutputInterface::VERBOSITY_DEBUG <= $output->getVerbosity(),
        )));

        if (false === strpos($host = $input->getOption('host'), '://')) {
            $host = 'tcp://'.$host;
        }

        if (!$socket = stream_socket_server($host, $errno, $errstr)) {
            throw new \RuntimeException(sprintf('Server start failed on "%s": %s %s.', $host, $errstr, $errno));
        }

        foreach ($this->getLogs($socket) as $clientId => $message) {
            $record = unserialize(base64_decode($message));

            // Impossible to decode the message, give up.
            if (false === $record) {
                continue;
            }

            if ($filter && !$this->el->evaluate($filter, $record)) {
                continue;
            }

            $this->displayLog($input, $output, $clientId, $record);
        }
    }

    private function getLogs($socket)
    {
        $sockets = array((int) $socket => $socket);
        $write = array();

        while (true) {
            $read = $sockets;
            stream_select($read, $write, $write, null);

            foreach ($read as $stream) {
                if ($socket === $stream) {
                    $stream = stream_socket_accept($socket);
                    $sockets[(int) $stream] = $stream;
                } elseif (feof($stream)) {
                    unset($sockets[(int) $stream]);
                    fclose($stream);
                } else {
                    yield (int) $stream => fgets($stream);
                }
            }
        }
    }

    private function displayLog(InputInterface $input, OutputInterface $output, $clientId, array $record)
    {
        if ($this->handler->isHandling($record)) {
            if (isset($record['log_id'])) {
                $clientId = unpack('H*', $record['log_id'])[1];
            }
            $logBlock = sprintf('<bg=%s> </>', self::$bgColor[$clientId % 8]);
            $output->write($logBlock);
        }

        $this->handler->handle($record);
    }
}
