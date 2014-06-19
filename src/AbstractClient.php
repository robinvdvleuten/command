<?php
namespace GuzzleHttp\Command;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Collection;
use GuzzleHttp\Event\ErrorEvent;
use GuzzleHttp\Event\HasEmitterTrait;
use GuzzleHttp\Event\RequestEvents;
use GuzzleHttp\Command\Exception\CommandException;
use GuzzleHttp\Command\Event\CommandEvents;
use GuzzleHttp\Command\Event\CommandErrorEvent;
use GuzzleHttp\Command\Event\ProcessEvent;

/**
 * Abstract client implementation that provides a basic implementation of
 * several methods. Concrete implementations may choose to extend this class
 * or to completely implement all of the methods of ServiceClientInterface.
 */
abstract class AbstractClient implements ServiceClientInterface
{
    use HasEmitterTrait;

    /** @var ClientInterface HTTP client used to send requests */
    private $client;

    /** @var Collection Service client configuration data */
    private $config;

    /**
     * The default client constructor is responsible for setting private
     * properties on the client and accepts an associative array of
     * configuration parameters:
     *
     * - defaults: Associative array of default command parameters to add to
     *   each command created by the client.
     *
     * Concrete implementations may choose to support additional configuration
     * settings as needed.
     *
     * @param ClientInterface $client Client used to send HTTP requests
     * @param array           $config Client configuration options
     */
    public function __construct(
        ClientInterface $client,
        array $config = []
    ) {
        $this->client = $client;
        // Ensure the defaults key is an array so we can easily merge later.
        if (!isset($config['defaults'])) {
            $config['defaults'] = [];
        }
        if (isset($config['emitter'])) {
            $this->emitter = $config['emitter'];
        }
        $this->config = new Collection($config);
    }

    public function __call($name, array $arguments)
    {
        return $this->execute(
            $this->getCommand($name, isset($arguments[0]) ? $arguments[0] : [])
        );
    }

    public function execute(CommandInterface $command)
    {
        $t = new CommandTransaction($this, $command);

        try {
            CommandEvents::prepare($t);
            // Listeners can intercept the event and inject a result. If that
            // happened, then we must not emit further events and just
            // return the result.
            if (null !== ($result = $t->getResult())) {
                return $result;
            }
            $t->setResponse($this->client->send($t->getRequest()));
            CommandEvents::process($t);
            return $t->getResult();
        } catch (CommandException $e) {
            // Let command exceptions pass through untouched
            throw $e;
        } catch (\Exception $e) {
            // Wrap any other exception in a CommandException so that exceptions
            // thrown from the client are consistent and predictable.
            $msg = 'Error executing command: ' . $e->getMessage();
            throw new CommandException($msg, $t, $e);
        }
    }

    public function executeAll($commands, array $options = [])
    {
        $requestOptions = [];
        // Move all of the options over that affect the request transfer
        if (isset($options['parallel'])) {
            $requestOptions['parallel'] = $options['parallel'];
        }

        // Create an iterator that yields requests from commands and send all
        $this->client->sendAll(
            new CommandToRequestIterator($commands, $this, $options),
            $this->preventCommandExceptions($requestOptions)
        );
    }

    public function batch($commands, array $options = [])
    {
        $hash = new \SplObjectStorage();
        foreach ($commands as $command) {
            $hash->attach($command);
        }

        $options = RequestEvents::convertEventArray(
            $options,
            ['process', 'error'],
            [
                'priority' => RequestEvents::EARLY,
                'once'     => true,
                'fn'       => function ($e) use ($hash) {
                        $hash[$e->getCommand()] = $e;
                    }
            ]
        );

        $this->executeAll($commands, $options);

        // Update the received value for any of the intercepted commands.
        foreach ($hash as $request) {
            if ($hash[$request] instanceof ProcessEvent) {
                $hash[$request] = $hash[$request]->getResult();
            } elseif ($hash[$request] instanceof CommandErrorEvent) {
                $hash[$request] = $hash[$request]
                    ->getRequestErrorEvent()
                    ->getException();
            }
        }

        return $hash;
    }

    public function getHttpClient()
    {
        return $this->client;
    }

    public function getConfig($keyOrPath = null)
    {
        if ($keyOrPath === null) {
            return $this->config->toArray();
        }

        if (strpos($keyOrPath, '/') === false) {
            return $this->config[$keyOrPath];
        }

        return $this->config->getPath($keyOrPath);
    }

    public function setConfig($keyOrPath, $value)
    {
        $this->config->setPath($keyOrPath, $value);
    }

    private function preventCommandExceptions(array $options)
    {
        // Prevent CommandExceptions from being thrown
        return RequestEvents::convertEventArray(
            $options,
            ['error'],
            [
                'priority' => RequestEvents::LATE,
                'fn'       => function (ErrorEvent $e) {
                        $e->stopPropagation();
                    }
            ]
        );
    }
}
