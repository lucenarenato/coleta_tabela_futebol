<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('football2', function (Blueprint $table) {
            $table->id(); // Adding primary key as it's a good practice
            $table->string('data', 10);
            $table->string('horario', 30);
            $table->text('liga');
            $table->text('casa');
            $table->text('visitante');
            $table->string('prediction', 50);
            $table->text('descricao');
            $table->timestamps(); // Adding created_at and updated_at columns
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('football2');
    }
};
