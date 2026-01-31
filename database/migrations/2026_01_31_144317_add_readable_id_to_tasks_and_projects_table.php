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
        Schema::table('projects', function (Blueprint $table) {
            $table->string('prefix', 10)->nullable()->after('slug');
            $table->unsignedInteger('last_task_number')->default(0)->after('prefix');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->string('readable_id')->nullable()->unique()->after('id');
        });

        // Backfill existing data
        $projects = \App\Models\Project::all();
        foreach ($projects as $project) {
            // Generate prefix from name (uppercase, remove vowels/spaces, max 4 chars) or fallback
            $name = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $project->name));
            $prefix = substr($name, 0, 3);
            if (strlen($prefix) < 2) $prefix = 'PROJ';
            
            // Ensure uniqueness of prefix if possible, but for now simple logic
            $project->prefix = $prefix;
            
            // Iterate tasks
            $tasks = $project->tasks()->orderBy('created_at')->get();
            $count = 0;
            foreach ($tasks as $task) {
                $count++;
                $task->readable_id = $prefix . '-' . $count;
                $task->saveQuietly();
            }
            
            $project->last_task_number = $count;
            $project->saveQuietly();
        }
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('readable_id');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['prefix', 'last_task_number']);
        });
    }
};
