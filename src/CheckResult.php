<?php

declare(strict_types=1);

namespace RoundlyConsulting\Alerts;

use Illuminate\Support\Str;
use RoundlyConsulting\Alerts\Enums\Status;
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
     * on the returned result and, for an exception, in `meta['exception_message']`.
     */
    public const int MAX_STORED_MESSAGE_LENGTH = 1000;

    public readonly Status $status;

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
     * The message as persisted: null when empty, otherwise bounded to
     * {@see self::MAX_STORED_MESSAGE_LENGTH} characters.
     */
    public function storedMessage(): ?string
    {
        if ($this->message === '') {
            return null;
        }

        return Str::limit($this->message, self::MAX_STORED_MESSAGE_LENGTH - 3);
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
     * class, message, and a bounded stack trace into meta.
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

        return new self(Status::Failed, $message !== '' ? $message : $e->getMessage(), [
            ...$meta,
            'exception' => $e::class,
            'exception_message' => $e->getMessage(),
            'exception_trace' => $trace,
        ]);
    }
}
