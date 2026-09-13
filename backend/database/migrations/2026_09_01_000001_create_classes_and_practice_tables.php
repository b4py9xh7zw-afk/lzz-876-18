<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 知识点弱项画像相关结构：
 * - classes：班级（教师查看共同薄弱点的分组依据）
 * - practice_sessions / practice_answers：弱项巩固练习（独立于正式成绩）
 * - users.class_id：学生所属班级
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'class_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('class_id')->nullable()->after('role')->comment('所属班级ID(仅学生)');
                $table->index('class_id');
            });
        }

        if (!Schema::hasTable('classes')) {
            Schema::create('classes', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100)->comment('班级名称');
                $table->string('description', 500)->nullable()->comment('班级描述');
                $table->unsignedBigInteger('created_by')->nullable()->comment('创建教师ID');
                $table->boolean('status')->default(1)->comment('状态: 1-正常 0-已解散');
                $table->timestamps();
                $table->index('created_by');
            });
        }

        if (!Schema::hasTable('practice_sessions')) {
            Schema::create('practice_sessions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->comment('练习学生ID');
                $table->enum('source', ['recommendation', 'wrong_questions', 'custom'])
                    ->default('recommendation')->comment('练习来源');
                $table->string('title', 200)->nullable()->comment('练习名称');
                $table->json('question_ids')->comment('本次练习题目ID列表');
                $table->integer('question_count')->default(0)->comment('题目数量');
                $table->integer('correct_count')->default(0)->comment('答对数量');
                $table->decimal('total_score', 6, 2)->default(0)->comment('练习总分(非成绩)');
                $table->decimal('score', 6, 2)->default(0)->comment('练习得分(非成绩)');
                $table->enum('status', ['in_progress', 'finished'])->default('in_progress');
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
                $table->index('user_id');
                $table->index('status');
            });
        }

        if (!Schema::hasTable('practice_answers')) {
            Schema::create('practice_answers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('practice_session_id');
                $table->unsignedBigInteger('question_id');
                $table->text('answer')->nullable()->comment('学生答案');
                $table->boolean('is_correct')->default(0);
                $table->decimal('score', 5, 2)->default(0)->comment('练习得分(非成绩)');
                $table->timestamps();
                $table->index('practice_session_id');
                $table->index('question_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('practice_answers');
        Schema::dropIfExists('practice_sessions');
        Schema::dropIfExists('classes');

        if (Schema::hasColumn('users', 'class_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropIndex(['class_id']);
                $table->dropColumn('class_id');
            });
        }
    }
};
