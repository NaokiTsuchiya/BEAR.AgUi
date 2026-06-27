<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input\Message;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserMessage::class)]
final class UserMessageTest extends TestCase
{
    public function testExposesIdAndText(): void
    {
        $msg = new UserMessage('m1', 'hi there');

        static::assertSame('m1', $msg->id);
        static::assertSame('hi there', $msg->text);
        static::assertSame('user', $msg->role());
    }
}
