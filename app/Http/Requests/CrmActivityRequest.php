<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class CrmActivityRequest extends FormRequest { public function authorize(): bool { return (bool) $this->user(); } public function rules(): array { return ['type'=>'required|in:call,email,whatsapp,meeting,note','subject'=>'nullable|string|max:255','body'=>'nullable|string','occurred_at'=>'nullable|date','lead_id'=>'nullable|integer','deal_id'=>'nullable|integer','customer_id'=>'nullable|integer']; } }
