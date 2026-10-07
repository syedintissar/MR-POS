<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Unit;
use Illuminate\Database\Seeder;

/**
 * Starter lists for a printer / toner / accessories shop. Everything here can be edited or removed later.
 * Safe to run again: only missing rows are created, nothing is overwritten.
 */
class CatalogueSeeder extends Seeder
{
    /** name => [short name, allows decimals] */
    public const UNITS = [
        'Piece' => ['pcs', false],
        'Box' => ['box', false],
        'Pack' => ['pack', false],
        'Set' => ['set', false],
        'Ream' => ['ream', false],
        'Metre' => ['m', true],
        'Gram' => ['g', true],
        'Kilogram' => ['kg', true],
        'Millilitre' => ['ml', true],
        'Litre' => ['l', true],
    ];

    /** category => list of subcategories */
    public const CATEGORIES = [
        'Printers' => [],
        'Toners' => [],
        'Cartridges' => [],
        'Ink' => [],
        'Printer Parts' => ['Drums', 'Blades', 'Rollers', 'Fusers', 'Other parts'],
        'Cables' => [],
        'Computer Accessories' => [],
        'Stationery' => [],
        'Services' => [],
        'Other' => [],
    ];

    public const BRANDS = ['HP', 'Canon', 'Epson', 'Brother', 'Samsung', 'Xerox', 'Kyocera', 'Ricoh', 'Lexmark', 'Pantum', 'Generic'];

    public function run(): void
    {
        foreach (self::UNITS as $name => [$short, $decimal]) {
            Unit::query()->firstOrCreate(['short_name' => $short], ['name' => $name, 'allows_decimal' => $decimal]);
        }

        $order = 0;
        foreach (self::CATEGORIES as $name => $children) {
            $parent = Category::query()->firstOrCreate(['parent_id' => null, 'name' => $name], ['sort_order' => $order += 10, 'is_active' => true]);
            $childOrder = 0;
            foreach ($children as $child) {
                Category::query()->firstOrCreate(['parent_id' => $parent->id, 'name' => $child], ['sort_order' => $childOrder += 10, 'is_active' => true]);
            }
        }

        foreach (self::BRANDS as $name) {
            Brand::query()->firstOrCreate(['name' => $name], ['is_active' => true]);
        }
    }
}
