<?php

declare(strict_types=1);

namespace App\Application\Support\Exceptions;

use App\Support\Errors\ErrorCode;
use App\Support\Errors\PlatformException;

final class AttachmentRefused extends PlatformException
{
    public static function extension(string $extension): self
    {
        return new self(
            (string) __('support.errors.attachment_extension', ['extension' => $extension]),
            ['extension' => $extension],
        );
    }

    public static function mimeType(string $mimeType): self
    {
        return new self(
            (string) __('support.errors.attachment_type'),
            ['mime_type' => $mimeType],
        );
    }

    public static function tooLarge(int $maxKilobytes): self
    {
        return new self(
            (string) __('support.errors.attachment_size', ['max' => $maxKilobytes]),
            ['max_kilobytes' => $maxKilobytes],
        );
    }

    public static function couldNotStore(): self
    {
        return new self((string) __('support.errors.attachment_failed'));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::ValidationFailed;
    }
}
