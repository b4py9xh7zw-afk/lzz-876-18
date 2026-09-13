<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClassRoom;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ClassController extends Controller
{
    protected function denyUnlessStaff(Request $request)
    {
        $user = $request->user();
        if (!$user->isAdmin() && !$user->isTeacher()) {
            return response()->json(['message' => '无权访问班级管理'], 403);
        }
        return null;
    }

    public function index(Request $request)
    {
        if ($response = $this->denyUnlessStaff($request)) {
            return $response;
        }

        $user = $request->user();

        $query = ClassRoom::withCount('students')->with('creator');
        if (!$user->isAdmin()) {
            $query->where('created_by', $user->id);
        }

        $classes = $query->where('status', 1)
            ->orderBy('id', 'asc')
            ->get()
            ->map(fn ($classRoom) => [
                'id' => $classRoom->id,
                'name' => $classRoom->name,
                'description' => $classRoom->description,
                'students_count' => $classRoom->students_count,
                'created_at' => $classRoom->created_at,
            ]);

        return response()->json(['classes' => $classes]);
    }

    public function store(Request $request)
    {
        if ($response = $this->denyUnlessStaff($request)) {
            return $response;
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $classRoom = ClassRoom::create([
            'name' => $request->name,
            'description' => $request->description,
            'created_by' => $request->user()->id,
            'status' => 1,
        ]);

        return response()->json([
            'message' => '创建成功',
            'class' => $classRoom,
        ], 201);
    }

    public function update(Request $request, ClassRoom $classRoom)
    {
        if (!$this->canManage($request->user(), $classRoom)) {
            return response()->json(['message' => '无权修改该班级'], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $classRoom->update($request->only(['name', 'description']));

        return response()->json(['message' => '更新成功', 'class' => $classRoom]);
    }

    public function destroy(Request $request, ClassRoom $classRoom)
    {
        if (!$this->canManage($request->user(), $classRoom)) {
            return response()->json(['message' => '无权删除该班级'], 403);
        }

        DB::transaction(function () use ($classRoom) {
            // 解散班级后学生回到未分班状态，不删除学生账号
            User::where('class_id', $classRoom->id)->update(['class_id' => null]);
            $classRoom->update(['status' => 0]);
        });

        return response()->json(['message' => '班级已解散']);
    }

    public function students(Request $request, ClassRoom $classRoom)
    {
        if (!$this->canManage($request->user(), $classRoom)) {
            return response()->json(['message' => '无权查看该班级'], 403);
        }

        $students = $classRoom->students()
            ->orderBy('id')
            ->get(['id', 'username', 'real_name', 'email', 'status', 'class_id']);

        // 尚未分班的学生，方便教师直接加入
        $unassigned = User::where('role', User::ROLE_STUDENT)
            ->whereNull('class_id')
            ->where('status', 1)
            ->orderBy('id')
            ->get(['id', 'username', 'real_name', 'email']);

        return response()->json([
            'class' => ['id' => $classRoom->id, 'name' => $classRoom->name],
            'students' => $students,
            'unassigned_students' => $unassigned,
        ]);
    }

    public function addStudent(Request $request, ClassRoom $classRoom)
    {
        if (!$this->canManage($request->user(), $classRoom)) {
            return response()->json(['message' => '无权操作该班级'], 403);
        }

        $validated = $request->validate([
            'student_ids' => 'required|array|min:1',
            'student_ids.*' => 'integer',
        ]);

        $updated = User::whereIn('id', $validated['student_ids'])
            ->where('role', User::ROLE_STUDENT)
            ->where('status', 1)
            ->update(['class_id' => $classRoom->id]);

        return response()->json(['message' => "已加入 {$updated} 名学生"]);
    }

    public function removeStudent(Request $request, ClassRoom $classRoom, User $student)
    {
        if (!$this->canManage($request->user(), $classRoom)) {
            return response()->json(['message' => '无权操作该班级'], 403);
        }

        if ($student->role !== User::ROLE_STUDENT || $student->class_id !== $classRoom->id) {
            return response()->json(['message' => '该学生不在此班级'], 422);
        }

        $student->update(['class_id' => null]);

        return response()->json(['message' => '已移出班级']);
    }

    protected function canManage(User $user, ClassRoom $classRoom): bool
    {
        return $user->isAdmin() || $classRoom->created_by === $user->id;
    }
}
