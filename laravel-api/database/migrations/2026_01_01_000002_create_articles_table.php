<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// NOTE on scope (see docs/architecture.md "Laravel API vs OJS data"):
// This table is owned and managed by the Research API — it is a
// *separate, simplified* dataset, NOT a live view of OJS's own
// submissions/publications tables. Reaching directly into OJS's
// internal database schema from another service is a fragile,
// undocumented integration (OJS doesn't publish its internal schema
// as a stable contract). The honest, maintainable way to integrate
// with real OJS article data is through OJS's own REST API
// (/index.php/<context>/api/v1/submissions), which is called out as
// a documented future improvement rather than implemented here.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('abstract')->nullable();
            $table->foreignId('researcher_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('draft'); // draft|submitted|published
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
