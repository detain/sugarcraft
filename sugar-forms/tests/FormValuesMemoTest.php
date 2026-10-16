<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SugarCraft\Forms\Field\Confirm;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Form;

/**
 * Pins for the values() memo (E736 plan 3.3): the map is derived once per
 * immutable Form snapshot, and a snapshot never serves a stale derivation.
 */
final class FormValuesMemoTest extends TestCase
{
    public function testFirstValuesCallPopulatesTheMemo(): void
    {
        $form = Form::new(Input::new('name'), Confirm::new('ok'));
        $memo = new ReflectionProperty(Form::class, 'valuesMemo');

        $this->assertNull($memo->getValue($form), 'memo must start empty');
        $values = $form->values();
        $this->assertSame(['name' => '', 'ok' => false], $values);
        $this->assertSame($values, $memo->getValue($form));
        $this->assertSame($values, $form->values());
    }

    public function testFreshSnapshotStartsWithAFreshMemo(): void
    {
        $form = Form::new(Input::new('name'));
        $form->values();
        $derived = $form->withWidth(80);
        $memo = new ReflectionProperty(Form::class, 'valuesMemo');

        // mutate() builds a new self(), so the sibling snapshot re-derives
        // rather than sharing the original's memo slot.
        $this->assertNull($memo->getValue($derived));
        $this->assertSame(['name' => ''], $derived->values());
        $this->assertNotNull($memo->getValue($derived));
    }

    public function testAccessorsReadTheMemoisedMap(): void
    {
        $form = Form::new(Input::new('city'), Confirm::new('agree'));
        $this->assertSame('', $form->get('city'));
        $this->assertFalse($form->get('agree'));
        $this->assertSame('fallback', $form->get('missing', 'fallback'));
        $memo = new ReflectionProperty(Form::class, 'valuesMemo');
        $this->assertNotNull($memo->getValue($form));
    }
}
