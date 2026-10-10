<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts;

use Illuminate\Support\Str;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\PackageToolkit\Support\Config;
use Throwable;

final class CheckResult
{
    /**
     * Number of stack-trace frames retained when capturing an exception, keeping
     * the persisted meta payload small.
     */
    public const int TRACE_FRAMES = 15;

    /**
     * Longest message persisted on a run or alert row. An exception message can embed a
     * whole SQL statement plus connection details; the columns are `text`, and the bound
     * keeps rows (and the notifications built from them) a sane size. The full text stays
     * on the returned result.
     */
    public const int MAX_STORED_MESSAGE_LENGTH = 1000;

    public readonly Status $status;

    /**
     * Whether the message is the text of a caught exception rather than the check's own.
     */
    private bool $messageFromException = false;

    /**
     * Meta keys holding exception details, left out of what is stored unless
     * `alerts.store_exception_details` is on.
     *
     * @var list<string>
     */
    private array $sensitiveMeta = [];

    /**
     * Shorthand: true only for a healthy (`Status::Ok`) result.
     */
    public bool $isOk {
        get => $this->status === Status::Ok;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        bool|Status $status = true,
        public readonly string $message = '',
        public readonly array $meta = [],
    ) {
        $this->status = $status instanceof Status
            ? $status
            : ($status ? Status::Ok : Status::Failed);
    }

    /**
     * The message as persisted on a run and an alert, and mailed: null when empty, otherwise
     * bounded to {@see self::MAX_STORED_MESSAGE_LENGTH} characters. The text of a caught
     * exception — which can carry hosts, SQL and credentials — is replaced by a line naming the
     * exception's class, unless `alerts.store_exception_details` is on.
     */
    public function storedMessage(): ?string
    {
        $message = $this->message;

        if ($this->messageFromException && ! self::storesExceptionDetails()) {
            $exception = $this->meta['exception'] ?? null;

            $message = (string) __('alerts::checks.check_errored', [
                'exception' => is_string($exception) ? class_basename($exception) : 'Throwable',
            ]);
        }

        if ($message === '') {
            return null;
        }

        return Str::limit($message, self::MAX_STORED_MESSAGE_LENGTH - 3);
    }

    /**
     * The meta as persisted on a run and an alert: without the exception's message and frames
     * (and a built-in check's caught `error`) unless `alerts.store_exception_details` is on.
     * The exception's class stays.
     *
     * @return array<string, mixed>
     */
    public function storedMeta(): array
    {
        if ($this->sensitiveMeta === [] || self::storesExceptionDetails()) {
            return $this->meta;
        }

        return array_diff_key($this->meta, array_flip($this->sensitiveMeta));
    }

    /**
     * A copy whose given meta keys hold exception details: kept on the returned result, left
     * out of what is stored unless `alerts.store_exception_details` is on.
     *
     * @internal used by the built-in checks for the error they caught
     */
    public function withSensitiveMeta(string ...$keys): self
    {
        $clone = clone $this;
        $clone->sensitiveMeta = array_values(array_unique([...$this->sensitiveMeta, ...$keys]));

        return $clone;
    }

    private static function storesExceptionDetails(): bool
    {
        return Config::boolean('alerts.store_exception_details', false);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function ok(string $message = '', array $meta = []): self
    {
        return new self(Status::Ok, $message, $meta);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function warning(string $message = '', array $meta = []): self
    {
        return new self(Status::Warning, $message, $meta);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function failed(string $message = '', array $meta = []): self
    {
        return new self(Status::Failed, $message, $meta);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function skipped(string $message = '', array $meta = []): self
    {
        return new self(Status::Skipped, $message, $meta);
    }

    /**
     * Turn a thrown exception into a clean failed result, capturing the exception
     * class, message, and a bounded stack trace into meta. The returned result keeps all of
     * it; what is stored keeps only the class (and an explicit `$message`) unless
     * `alerts.store_exception_details` is on — see storedMessage() and storedMeta().
     *
     * @param  array<string, mixed>  $meta
     */
    public static function fromException(Throwable $e, string $message = '', array $meta = []): self
    {
        $trace = array_slice(
            explode("\n", $e->getTraceAsString()),
            0,
            self::TRACE_FRAMES,
        );

        $result = new self(Status::Failed, $message !== '' ? $message : $e->getMessage(), [
            ...$meta,
            'exception' => $e::class,
            'exception_message' => $e->getMessage(),
            'exception_trace' => $trace,
        ]);

        $result->messageFromException = $message === '';
        $result->sensitiveMeta = ['exception_message', 'exception_trace'];

        return $result;
    }
}
