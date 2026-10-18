<?php

namespace Database\Factories;

use App\Models\EDetailingMaterial;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EDetailingMaterialFactory extends Factory
{
    protected $model = EDetailingMaterial::class;

    public function definition(): array
    {
        $path = EDetailingMaterial::uploadDirectory().'/'.Str::uuid().'.pdf';
        Storage::disk('local')->put($path, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");

        return ['title' => fake()->sentence(3), 'file_path' => $path, 'is_active' => true];
    }
}
