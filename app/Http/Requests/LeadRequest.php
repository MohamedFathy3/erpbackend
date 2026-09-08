<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class LeadRequest extends FormRequest { public function authorize(): bool { return (bool) $this->user(); } public function rules(): array { return ['name'=>'required|string|max:255','company'=>'nullable|string|max:255','email'=>'nullable|email|max:255','phone'=>'nullable|string|max:50','source'=>'nullable|string|max:100','status'=>'nullable|string|max:50','assigned_to'=>'nullable|integer','customer_id'=>'nullable|integer','notes'=>'nullable|string']; } }
