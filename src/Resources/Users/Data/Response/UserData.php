<?php

namespace XtendPackages\RESTPresenter\Resources\Users\Data\Response;

use Carbon\Carbon;
use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

class UserData extends Data
{
    public function __construct(
        public int $id,
        #[Min(3)]
        public string $name,
        #[Email(Email::RfcValidation)]
        public string $email,
        #[WithCast(DateTimeInterfaceCast::class)]
        public Carbon | Optional $email_verified_at,
        #[WithCast(DateTimeInterfaceCast::class)]
        public Carbon | Optional $created_at,
        #[WithCast(DateTimeInterfaceCast::class)]
        public Carbon | Optional $updated_at,
    ) {
    }
}
