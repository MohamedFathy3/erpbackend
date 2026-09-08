<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class EmailTemplateRequest extends FormRequest
{
    public function authorize(): bool { return (bool) $this->user(); }
    public function rules(): array { return ['name'=>'required|string|max:255','slug'=>'nullable|string|max:150','subject'=>'required|string|max:255','body'=>'required|string','bcc'=>'nullable|string','is_active'=>'nullable|boolean']; }
}
