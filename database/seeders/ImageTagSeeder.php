<?php

namespace Database\Seeders;

use App\Models\ImageTag;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ImageTagSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->seedGlobalTags();
        $this->seedPharmaTags();

    }

    protected function seedGlobalTags(): void
    {
        $tags = [
            'General Visit',
            'Customer Meeting',
            'Site Photo',
            'Invoice Copy',
            'Document Proof',
            'check-in',
            'check-out',
        ];

        foreach ($tags as $name) {
            ImageTag::updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'industry_type' => null,
                    'is_active' => true,
                ]
            );
        }
    }

    protected function seedPharmaTags(): void
    {
        $tags = [
            'Doctor Visit',
            'Chemist Visit',
            'Prescription Image',
            'Sample Distribution',
            'Clinic Board',
            'Product Detailing',
        ];

        foreach ($tags as $name) {
            ImageTag::updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'industry_type' => 'pharma',
                    'is_active' => true,

                ]
            );
        }
    }

    protected function seedFmcgTags(): void
    {
        $tags = [
            'Shelf Display',
            'Stock Position',
            'Retail Counter',
            'Promotional Material',
            'Competitor Product',
            'Merchandising',
        ];

        foreach ($tags as $name) {
            ImageTag::updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'industry_type' => 'fmcg',
                    'is_active' => true,

                ]
            );
        }
    }

    protected function seedMachineryTags(): void
    {
        $tags = [
            'Machine Installation',
            'Site Inspection',
            'Demo Session',
            'Service Visit',
            'Machine Fault',
            'Maintenance Check',
        ];

        foreach ($tags as $name) {
            ImageTag::updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'industry_type' => 'machinery',
                    'is_active' => true,

                ]
            );
        }
    }
}
