<?php

declare(strict_types=1);

namespace Pulse;

use Pulse\Http\Api;
use Pulse\Http\Transport;
use Pulse\Logs\LogShipper;
use Pulse\Logs\LogSpool;
use Pulse\Storage\CronStore;
use Pulse\Storage\StateStore;

final class Runtime
{
    private function __construct(
        public readonly Config $config,
        public readonly Reporter $reporter,
        public readonly ?LogShipper $shipper,
        public readonly Worker $worker,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, string>|null $env
     */
    public static function create(
        PackageMeta $meta,
        string $directory,
        array $options = [],
        ?array $env = null,
        ?Logger $logger = null,
        ?Transport $transport = null,
        ?\Closure $clock = null,
    ): ?self {
        $config = Config::resolve($options, $env, $logger);
        if ($config === null) {
            return null;
        }
        $api = new Api($config, $transport ?? Api::transportFor($config), $meta->userAgent());
        $state = new ServerState(StateStore::in($directory));
        $reporter = new Reporter($meta, $config, $api, $state, CronStore::in($directory));
        $shipper = $config->logs()
            ? new LogShipper($config, $api, $state, LogSpool::in($directory), static fn (string $message) => $reporter->warnOnce($message))
            : null;

        return new self($config, $reporter, $shipper, new Worker($reporter, $shipper, $state, $clock));
    }
}
