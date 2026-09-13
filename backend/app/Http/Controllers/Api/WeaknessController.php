<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClassRoom;
use App\Models\User;
use App\Services\RecommendationService;
use App\Services\WeaknessProfileService;
use Illuminate\Http\Request;

class WeaknessController extends Controller
{
    public function __construct(
        private WeaknessProfileService $profiles,
        private RecommendationService $recommendations
    ) {
    }

    /**
     * 当前学生的知识点弱项画像
     */
    public function myProfile(Request $request)
    {
        return response()->json([
            'profile' => $this->profiles->studentProfile($request->user()->id),
        ]);
    }

    /**
     * 指定学生的画像（教师/管理员可查，且学生必须与教师存在班级关联或为管理员）
     */
    public function studentProfile(Request $request, User $student)
    {
        $user = $request->user();

        if (!$user->isAdmin() && !$user->isTeacher()) {
            return response()->json(['message' => '无权访问'], 403);
        }

        if ($student->role !== User::ROLE_STUDENT) {
            return response()->json(['message' => '用户不是学生'], 422);
        }

        if (!$user->isAdmin() && !$this->sharesClass($user, $student)) {
            return response()->json(['message' => '该学生不在您管理的班级中'], 403);
        }

        return response()->json([
            'profile' => $this->profiles->studentProfile($student->id),
        ]);
    }

    /**
     * 当前学生的练习推荐（真实错题 + 相近题）
     */
    public function myRecommendations(Request $request)
    {
        $limit = (int) $request->input('limit', 10);

        return response()->json(
            $this->recommendations->recommend($request->user()->id, $limit > 0 ? $limit : null)
        );
    }

    /**
     * 教师：班级共同薄弱点
     */
    public function classProfile(Request $request, ClassRoom $classRoom)
    {
        $user = $request->user();

        if (!$user->isAdmin() && !$user->isTeacher()) {
            return response()->json(['message' => '无权访问'], 403);
        }

        if (!$user->isAdmin() && $classRoom->created_by !== $user->id) {
            return response()->json(['message' => '无权查看该班级'], 403);
        }

        return response()->json([
            'profile' => $this->profiles->classProfile($classRoom),
        ]);
    }

    protected function sharesClass(User $teacher, User $student): bool
    {
        if (!$student->class_id) {
            return false;
        }
        $classRoom = ClassRoom::find($student->class_id);
        return $classRoom !== null && $classRoom->created_by === $teacher->id;
    }
}
