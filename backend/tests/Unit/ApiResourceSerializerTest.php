<?php

namespace Tests\Unit;

use App\Serializer\ApiResourceSerializer;
use Tests\TestCase;

class ApiResourceSerializerTest extends TestCase
{
    public function test_format_timestamp_to_iso8601_utc(): void
    {
        $result = ApiResourceSerializer::formatTimestamp('2026-01-01 12:00:00.123');
        $this->assertStringEndsWith('Z', $result);
        $this->assertStringStartsWith('2026-01-01T12:00:00', $result);
    }

    public function test_out_decodes_json_columns(): void
    {
        $row = ['metadata' => '{"key":"value"}', 'is_active' => 1, 'name' => 'Jane'];
        $out = ApiResourceSerializer::out($row);

        $this->assertSame(['key' => 'value'], $out['metadata']);
        $this->assertTrue($out['is_active']);
        $this->assertSame('Jane', $out['name']);
    }

    public function test_in_value_coercions(): void
    {
        $this->assertSame('{"a":1}', ApiResourceSerializer::inValue(['a' => 1]));
        $this->assertSame(1, ApiResourceSerializer::inValue('true'));
        $this->assertSame(0, ApiResourceSerializer::inValue('false'));
        $this->assertNull(ApiResourceSerializer::inValue('null'));
        $this->assertSame(1, ApiResourceSerializer::inValue(true));
    }

    public function test_in_filters_unknown_columns(): void
    {
        $result = ApiResourceSerializer::in(
            ['name' => 'Jane', 'evil' => 'x'],
            ['name']
        );

        $this->assertSame(['name' => 'Jane'], $result);
    }
}
