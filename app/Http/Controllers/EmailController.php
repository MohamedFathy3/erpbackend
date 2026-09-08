<?php
namespace App\Http\Controllers;

use App\Http\Requests\EmailTemplateRequest;
use App\Jobs\SendCrmEmail;
use App\Models\Customer;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EmailController extends Controller
{
    public function templates() { return response()->json(['data'=>EmailTemplate::where('is_active', true)->orderBy('name')->get()]); }
    public function storeTemplate(EmailTemplateRequest $request) { $template=EmailTemplate::create($request->validated()); return response()->json(['data'=>$template], 201); }
    public function updateTemplate(EmailTemplateRequest $request, EmailTemplate $emailTemplate) { $emailTemplate->update($request->validated()); return response()->json(['data'=>$emailTemplate->fresh()]); }
    public function destroyTemplate(EmailTemplate $emailTemplate) { $emailTemplate->delete(); return response()->json(['message'=>'deleted']); }
    public function logs(Request $request) { $query=EmailLog::with(['customer','template'])->latest(); if ($request->filled('status')) $query->where('status',$request->string('status')); return response()->json(['data'=>$query->paginate($request->integer('per_page', 25))]); }

    public function sendToCustomer(Request $request, Customer $customer)
    {
        $data=$request->validate(['to'=>'nullable|email','subject'=>'required|string|max:255','body'=>'required|string','template_id'=>'nullable|integer']);
        $to=$data['to'] ?? $customer->email;
        abort_unless($to, 422, 'Customer does not have an email address.');
        $template = null;
        if (!empty($data['template_id'])) { $template=EmailTemplate::find($data['template_id']); abort_unless($template, 404); }
        $body=$this->replaceVariables($data['body'], $customer);
        $subject=$this->replaceVariables($data['subject'], $customer);
        $log=EmailLog::withoutGlobalScopes()->create(['tenant_id'=>$customer->tenant_id,'customer_id'=>$customer->id,'template_id'=>$data['template_id'] ?? null,'to_email'=>$to,'subject'=>$subject,'status'=>'queued']);
        SendCrmEmail::dispatch($log->id, $to, $subject, $body, $template->bcc ?? null);
        activity()->performedOn($customer)->withProperties(['email_log_id'=>$log->id,'to'=>$to,'subject'=>$subject])->log('crm email queued');
        return response()->json(['data'=>['id'=>$log->id,'status'=>'queued','message'=>'Email queued successfully']], 202);
    }

    private function replaceVariables(string $value, Customer $customer): string { return str_replace(['{{customer_name}}','{{name}}','{{email}}','{{phone}}'], [$customer->name ?? '',$customer->name ?? '',$customer->email ?? '',$customer->phone ?? ''], $value); }
}
