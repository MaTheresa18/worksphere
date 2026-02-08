<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isDraft = $this->boolean('is_draft');

        return [
            'account_id' => [
                'required',
                function ($attribute, $value, $fail) {
                    if (is_numeric($value)) {
                        if (! \App\Models\EmailAccount::where('id', $value)->exists()) {
                            $fail('The selected account id is invalid.');
                        }
                    } elseif (\Illuminate\Support\Str::isUuid($value)) {
                        if (! \App\Models\EmailAccount::where('public_id', $value)->exists()) {
                            $fail('The selected account id is invalid.');
                        }
                    } else {
                        $fail('The selected account id must be a valid ID or UUID.');
                    }
                },
            ],
            'to' => [$isDraft ? 'nullable' : 'required', 'array', $isDraft ? '' : 'min:1'],
            'to.*.email' => ['required_with:to', 'email'],
            'to.*.name' => ['nullable', 'string', 'max:255'],
            'cc' => ['nullable', 'array'],
            'cc.*.email' => ['required_with:cc', 'email'],
            'cc.*.name' => ['nullable', 'string', 'max:255'],
            'bcc' => ['nullable', 'array'],
            'bcc.*.email' => ['required_with:bcc', 'email'],
            'bcc.*.name' => ['nullable', 'string', 'max:255'],
            'subject' => [$isDraft ? 'nullable' : 'required', 'string', 'max:998'],
            'body' => [$isDraft ? 'nullable' : 'required', 'string'],
            'signature_id' => ['nullable', 'exists:email_signatures,id'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['file', 'max:25600'], // 25MB
            'is_draft' => ['boolean'],
            'draft_id' => ['nullable', 'exists:emails,id'],
            'request_read_receipt' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'to.required' => 'Please specify at least one recipient.',
            'to.*.email.email' => 'One or more recipient email addresses are invalid.',
            'subject.required' => 'Subject is required.',
            'body.required' => 'Email body is required.',
        ];
    }
}
