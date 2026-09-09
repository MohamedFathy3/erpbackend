<?php
namespace App\Http\Controllers;
use App\Jobs\SendCrmEmailJob;
use App\Models\Customer;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use Illuminate\Http\Request;
class CrmEmailController extends Controller {
    public function templates() { return response()->json(['data'=>EmailTemplate::query()->latest()->get()]); }
    public function storeTemplate(Request $request) { $template=EmailTemplate::create($request->validate(['name'=>'required|string|max:120','subject'=>'required|string|max:255','body'=>'required|string'])); return response()->json(['data'=>$template],201); }
    public function logs(Request $request) { $query=EmailLog::query()->with('customer')->latest(); if($request->filled('status')) $query->where('status',$request->string('status')); return response()->json(['data'=>$query->paginate($request->integer('per_page',25))]); }
    public function sendToCustomer(Request $request, Customer $customer) { abort_unless($customer->email, 422, 'Customer has no email address.'); $data=$request->validate(['template_id'=>'nullable|exists:email_templates,id','subject'=>'required_without:template_id|string|max:255','body'=>'required_without:template_id|string']); $template=$data['template_id'] ? EmailTemplate::query()->findOrFail($data['template_id']) : null; $log=EmailLog::create(['customer_id'=>$customer->id,'template_id'=>$template?->id,'to_email'=>$customer->email,'subject'=>$template?->subject ?? $data['subject'],'body'=>$template?->body ?? $data['body'],'status'=>'queued']); SendCrmEmailJob::dispatch($log->id); return response()->json(['data'=>$log],202); }
}
