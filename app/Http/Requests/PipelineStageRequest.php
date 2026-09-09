<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class PipelineStageRequest extends FormRequest { public function authorize(): bool { return (bool) $this->user(); } public function rules(): array { return ['name'=>'required|string|max:100','name_ar'=>'nullable|string|max:100','sort_order'=>'nullable|integer|min:0','is_won'=>'nullable|boolean','is_lost'=>'nullable|boolean']; } }
