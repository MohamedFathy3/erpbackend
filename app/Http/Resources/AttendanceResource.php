<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'         => $this->id,
            'employee' => [
                'name' => $this->employee?->name,
                'employee_code'   => $this->employee?->employee_code,
                'biometric_user_id' => $this->employee?->biometric_user_id,
            ],
            'date'       => $this->date?->format('Y-m-d'),


            'check_in'   => $this->check_in?->format('H:i:s'),
            'check_out'  => $this->check_out?->format('H:i:s'),
            'status'     => $this->status,
            'source' => $this->source,
            'late_minutes' => (int) ($this->late_minutes ?? 0),
            'worked_minutes' => (int) ($this->worked_minutes ?? 0),

            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
