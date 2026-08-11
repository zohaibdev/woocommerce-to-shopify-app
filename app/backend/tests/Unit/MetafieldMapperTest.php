<?php

namespace Tests\Unit;

use App\Migration\MetafieldMapper;
use Tests\TestCase;

class MetafieldMapperTest extends TestCase
{
    public function test_maps_scalar_and_array_meta_with_sanitised_keys(): void
    {
        $mapper = new MetafieldMapper();

        $fields = $mapper->fromMetaData(1, [
            ['key' => '_wpcom_is_markdown', 'value' => '1'],       // leading underscore stripped
            ['key' => 'Custom Field!', 'value' => 'hello'],         // invalid chars -> underscores
            ['key' => 'specs', 'value' => ['color' => 'red']],      // array -> json
            ['key' => 'empty', 'value' => ''],                      // skipped
        ]);

        $byKey = collect($fields)->keyBy('key');

        $this->assertTrue($byKey->has('wpcom_is_markdown'));
        $this->assertSame('multi_line_text_field', $byKey['wpcom_is_markdown']['type']);

        $this->assertTrue($byKey->has('custom_field'));

        $this->assertTrue($byKey->has('specs'));
        $this->assertSame('json', $byKey['specs']['type']);
        $this->assertSame('{"color":"red"}', $byKey['specs']['value']);

        $this->assertFalse($byKey->has('empty'));
    }

    public function test_brand_metafield_is_json_list(): void
    {
        $mapper = new MetafieldMapper();

        $mf = $mapper->brandMetafield(1, ['Prada', 'Gucci']);

        $this->assertSame('brands', $mf['key']);
        $this->assertSame('json', $mf['type']);
        $this->assertSame('["Prada","Gucci"]', $mf['value']);

        $this->assertNull($mapper->brandMetafield(1, []));
    }
}
