<?php

namespace Database\Seeders;

use App\Models\ChatIntent;
use App\Support\ChatKnowledge;
use Illuminate\Database\Seeder;

class ChatIntentSeeder extends Seeder
{
    public function run(): void
    {
        foreach (ChatKnowledge::intents() as $intent) {
            ChatIntent::updateOrCreate(
                ['nombre' => $intent['nombre']],
                $intent,
            );
        }
    }
}
