<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request) { $notifications=$request->user()->notifications()->latest()->paginate($request->integer('per_page',20)); return response()->json(['data'=>$notifications]); }
    public function unreadCount(Request $request) { return response()->json(['data'=>['count'=>$request->user()->unreadNotifications()->count()]]); }
    public function read(Request $request, string $id) { $notification=$request->user()->notifications()->whereKey($id)->firstOrFail(); $notification->markAsRead(); return response()->json(['data'=>$notification]); }
    public function readAll(Request $request) { $request->user()->unreadNotifications->markAsRead(); return response()->json(['message'=>'All notifications marked as read']); }
}
