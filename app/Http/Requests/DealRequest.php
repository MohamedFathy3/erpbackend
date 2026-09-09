<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class DealRequest extends FormRequest { public function authorize(): bool { return (bool) $this->user(); } public function rules(): array { return ['title'=>'required|string|max:255','value'=>'nullable|numeric|min:0','stage_id'=>'required|integer','lead_id'=>'nullable|integer','customer_id'=>'nullable|integer','assigned_to'=>'nullable|integer','expected_close_date'=>'nullable|date','notes'=>'nullable|string']; } }
