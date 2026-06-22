<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Tests\Unit\Event;

use JsonSerializable;
use NaokiTsuchiya\BEARAgUi\Event\TextMessageContent;
use NaokiTsuchiya\BEARAgUi\Event\TextMessageEnd;
use NaokiTsuchiya\BEARAgUi\Event\TextMessageStart;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

#[CoversClass(TextMessageStart::class)]
#[CoversClass(TextMessageContent::class)]
#[CoversClass(TextMessageEnd::class)]
final class TextMessageEventTest extends TestCase
{
    public function testTextMessageStartDefaultsToAssistant(): void
    {
        $event = new TextMessageStart('m-1');
        static::assertSame('{"type":"TEXT_MESSAGE_START","messageId":"m-1","role":"assistant"}', $this->encode($event));
    }

    public function testTextMessageContent(): void
    {
        $event = new TextMessageContent('m-1', 'hi');
        static::assertSame('{"type":"TEXT_MESSAGE_CONTENT","messageId":"m-1","delta":"hi"}', $this->encode($event));
    }

    public function testTextMessageEnd(): void
    {
        $event = new TextMessageEnd('m-1');
        static::assertSame('{"type":"TEXT_MESSAGE_END","messageId":"m-1"}', $this->encode($event));
    }

    private function encode(JsonSerializable $event): string
    {
        return json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
