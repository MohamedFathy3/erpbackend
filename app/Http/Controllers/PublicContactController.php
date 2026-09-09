<?php
namespace App\Http\Controllers;

use App\Jobs\SendTrialEmail;
use Illuminate\Http\Request;

class PublicContactController extends Controller
{
    public function store(Request $request) { $data=$request->validate(['name'=>'required|string|max:255','email'=>'required|email|max:255','phone'=>'nullable|string|max:50','message'=>'required|string|max:5000']); $to=config('mail.from.address'); SendTrialEmail::dispatch($to, 'New website contact from '.$data['name'], '<h2>Website Contact</h2><p><strong>Name:</strong> '.e($data['name']).'</p><p><strong>Email:</strong> '.e($data['email']).'</p><p><strong>Phone:</strong> '.e($data['phone'] ?? '-').'</p><p>'.nl2br(e($data['message'])).'</p>'); return response()->json(['message'=>'Your message was queued successfully.'],202); }
}
