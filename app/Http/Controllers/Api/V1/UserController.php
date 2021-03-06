<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AddOwnerNodeRequest;
use App\Http\Requests\Api\ChangeEmailRequest;
use App\Http\Requests\Api\ChangePasswordRequest;
use App\Http\Requests\Api\ResendEmailRequest;
use App\Http\Requests\Api\SubmitKYCRequest;
use App\Http\Requests\Api\SubmitPublicAddressRequest;
use App\Http\Requests\Api\VerifyFileCasperSignerRequest;
use App\Mail\AddNodeMail;
use App\Mail\UserVerifyMail;
use App\Models\OwnerNode;
use App\Models\Profile;
use App\Models\ShuftiproTemp;
use App\Models\User;
use App\Models\VerifyUser;
use App\Repositories\OwnerNodeRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\UserRepository;
use App\Repositories\VerifyUserRepository;
use App\Services\CasperSignature;
use App\Services\CasperSigVerify;
use App\Services\ShuftiproCheck;
use App\Services\Test;
use Exception;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    private $userRepo;
    private $verifyUserRepo;
    private $profileRepo;
    private $ownerNodeRepo;

    /* Create a new controller instance.
     *
     * @param UserRepository $userRepo userRepo
     *
     * @return void
     */
    public function __construct(
        UserRepository $userRepo,
        VerifyUserRepository $verifyUserRepo,
        ProfileRepository $profileRepo,
        OwnerNodeRepository $ownerNodeRepo
    ) {
        $this->userRepo = $userRepo;
        $this->verifyUserRepo = $verifyUserRepo;
        $this->profileRepo = $profileRepo;
        $this->ownerNodeRepo = $ownerNodeRepo;
    }

    /**
     * change email
     */
    public function changeEmail(ChangeEmailRequest $request)
    {
        try {
            DB::beginTransaction();
            $user = auth()->user();
            $user->update(['email' => $request->email, 'email_verified_at' => null]);
            $code = generateString(7);
            $userVerify = $this->verifyUserRepo->updateOrCreate(
                [
                    'email' => $request->email,
                    'type' => VerifyUser::TYPE_VERIFY_EMAIL,
                ],
                [
                    'code' => $code,
                    'created_at' => now()
                ]
            );
            if ($userVerify) {
                Mail::to($request->email)->send(new UserVerifyMail($code));
            }
            DB::commit();
            return $this->metaSuccess();
        } catch (\Exception $ex) {
            return $this->errorResponse(__('api.error.internal_error'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    /**
     * Change password
     */
    public function changePassword(ChangePasswordRequest $request)
    {
        $user = auth()->user();
        if (Hash::check($request->new_password, $user->password)) {
            return $this->errorResponse(__('api.error.not_same_current_password'), Response::HTTP_BAD_REQUEST);
        }
        $newPassword = bcrypt($request->new_password);
        $user->update(['password' => $newPassword]);
        return $this->metaSuccess();
    }

    /**
     * Get user profile
     */
    public function getProfile()
    {
        $user = auth()->user()->load('profile');
        return $this->successResponse($user);
    }

    /**
     * loggout user
     */
    public function logout()
    {
        auth()->user()->token()->revoke();
        return $this->metaSuccess();
    }

    /**
     * verify file casper singer
     */
    public function uploadLetter(Request $request)
    {
        try {
            // Validator
            $validator = Validator::make($request->all(), [
                'file' => 'required|mimes:pdf|max:20000',
            ]);
            if ($validator->fails()) {
                return $this->validateResponse($validator->errors());
            }
            $user = auth()->user();
            $filenameWithExt = $request->file('file')->getClientOriginalName();
            //Get just filename
            $filename = pathinfo($filenameWithExt, PATHINFO_FILENAME);
            // Get just ext
            $extension = $request->file('file')->getClientOriginalExtension();
            // Filename to store
            $fileNameToStore = $filename . '_' . time() . '.' . $extension;
            // Upload Image
            $path = $request->file('file')->storeAs('users', $fileNameToStore);
            $user->letter_file = $path;
            $user->save();
            return $this->metaSuccess();
        } catch (\Exception $ex) {
            return $this->errorResponse(__('Failed upload file'), Response::HTTP_BAD_REQUEST, $ex->getMessage());
        }
    }

    /**
     * Send Hellosign Request
     */
    public function sendHellosignRequest()
    {
        $user = auth()->user();
        if ($user) {
            $client_key = config('services.hellosign.api_key');
            $client_id = config('services.hellosign.client_id');
            $template_id = '7de53a8a63cbcb8a6119589e1cd5e624fac8358a';
            $client = new \HelloSign\Client($client_key);
            $request = new \HelloSign\TemplateSignatureRequest;

            $request->enableTestMode();
            $request->setTemplateId($template_id);
            $request->setSubject('User Agreement');
            $request->setSigner('User', $user->email, $user->first_name . ' ' . $user->last_name);
            $request->setCustomFieldValue('FullName', $user->first_name . ' ' . $user->last_name);
            $request->setCustomFieldValue('FullName2', $user->first_name . ' ' . $user->last_name);
            $request->setClientId($client_id);

            $initial = strtoupper(substr($user->first_name, 0, 1)) . strtoupper(substr($user->last_name, 0, 1));
            $request->setCustomFieldValue('Initial', $initial);

            $embedded_request = new \HelloSign\EmbeddedSignatureRequest($request, $client_id);
            $response = $client->createEmbeddedSignatureRequest($embedded_request);

            $signature_request_id = $response->getId();

            $signatures = $response->getSignatures();
            $signature_id = $signatures[0]->getId();

            $response = $client->getEmbeddedSignUrl($signature_id);
            $sign_url = $response->getSignUrl();

            $user->update(['signature_request_id' => $signature_request_id]);
            return $this->successResponse([
                'signature_request_id' => $signature_request_id,
                'url' => $sign_url,
            ]);
        }
        return $this->errorResponse(__('Hellosign request fail'), Response::HTTP_BAD_REQUEST);
    }
    /**
     * verify bypass
     */
    public function verifyBypass(Request $request)
    {
        $user = auth()->user();
         // Validator
         $validator = Validator::make($request->all(), [
            'type' => 'required'
        ]);
        if ($validator->fails()) {
            return $this->validateResponse($validator->errors());
        }
        if ($request->type == 'hellosign') {
            $user->signature_request_id = 'signature_'  . $user->id . '._id';
            $user->hellosign_form = 'hellosign_form_' . $user->id;
            $user->letter_file = 'leteter_file.pdf';
            $user->save();
        }

        if ($request->type == 'verify-node') {
            $user->public_address_node = 'public_address_node'  . $user->id ;
            $user->node_verified_at = now();
            $user->message_content = 'message_content';
            $user->signed_file = 'signture';
            $user->save();
        }

        if ($request->type == 'submit-kyc') {
            $user->kyc_verified_at = now();
            $user->save();
            if(!$user->profile) {
                $profile = new Profile();
                $profile->user_id = $user->id;
                $profile->first_name = $user->first_name;
                $profile->last_name = $user->last_name;
                $profile->dob = '1990-01-01';
                $profile->country_citizenship ='United States';
                $profile->country_residence = 'United States';
                $profile->address = 'New York';
                $profile->city = 'New York';
                $profile->zip = '10025';
                $profile->type_owner_node = 1;
                $profile->type = $user->type;
                $profile->save();
            }

        }

        return $this->metaSuccess();
    }

    /**
     * submit node address
     */
    public function submitPublicAddress(SubmitPublicAddressRequest $request)
    {
        $user = auth()->user();
        $user->update(['public_address_node' => $request->public_address]);
        return $this->metaSuccess();
    }

    /**
     * submit node address
     */
    public function getMessageContent()
    {
        $user = auth()->user();
        $timestamp = date('m/d/Y');
        $message = "Please use the Casper Signature python tool to sign this message! " . $timestamp;
        $user->update(['message_content' => $message]);
        $filename = 'message.txt';
        return response()->streamDownload(function () use ($message) {
            echo $message;
        }, $filename);
    }

    /**
     * verify file casper singer
     */
    public function verifyFileCasperSigner(VerifyFileCasperSignerRequest $request)
    {
        try {
            $casperSigVerify = new CasperSigVerify();
            $user = auth()->user();
            $message = $user->message_content;
            $public_validator_key = $user->public_address_node;
            $file = $request->file;

            $name = $file->getClientOriginalName();
            $hexstring = $file->get();

            if (
                $hexstring &&
                $name == 'signature'
            ) {
                $verified = $casperSigVerify->verify(
                    trim($hexstring),
                    $public_validator_key,
                    $message
                );
                // $verified = true;
                if ($verified) {

                    $fullpath = 'sigfned_file/' . $user->id . '/signature';
                    Storage::disk('local')->put($fullpath,  trim($hexstring));
                    // $url = Storage::disk('local')->url($fullpath);
                    $user->signed_file = $fullpath;
                    $user->node_verified_at = now();
                    $user->save();
                    return $this->metaSuccess();
                } else {
                    return $this->errorResponse(__('Failed verification'), Response::HTTP_BAD_REQUEST);
                }
            }
            return $this->errorResponse(__('Failed verification'), Response::HTTP_BAD_REQUEST);
        } catch (\Exception $ex) {
            return $this->errorResponse(__('Failed verification'), Response::HTTP_BAD_REQUEST, $ex->getMessage());
        }
    }

    /**
     * submit KYC
     */
    public function functionSubmitKYC(SubmitKYCRequest $request)
    {
        $user = auth()->user();
        $data = $request->validated();
        $data['dob'] = \Carbon\Carbon::parse($request->dob)->format('Y-m-d');
        $user->update(['member_status' => User::STATUS_INCOMPLETE]);
        $this->profileRepo->updateOrCreate(
            [
                'user_id' => $user->id,
            ],
            $data
        );
        return $this->metaSuccess();
    }

    /**
     * verify owner node
     */
    public function verifyOwnerNode(Request $request)
    {
        $user = auth()->user();
        $this->profileRepo->updateConditions(
            ['type_owner_node' => $request->type],
            ['user_id' => $user->id]
        );
        return $this->metaSuccess();
    }

    /**
     * add owner node
     */
    public function addOwnerNode(AddOwnerNodeRequest $request)
    {
        try {
            $user = auth()->user();
            $data = $request->validated();
            $ownerNodes = [];
            $percents = 0;
            foreach ($data as $value) {
                $percents += $value['percent'];
                $value['user_id'] = $user->id;
                $value['created_at'] = now();
                array_push($ownerNodes, $value);
            }
            if ($percents >= 100) {
                return $this->errorResponse(__('Total percent must less 100'), Response::HTTP_BAD_REQUEST);
            }

            OwnerNode::where('user_id', $user->id)->delete();
            OwnerNode::insert($ownerNodes);
            $user->update(['kyc_verified_at' => now()]);

            $url = $request->header('origin') ?? $request->root();
            $resetUrl = $url . '/register-type';
            foreach ($ownerNodes as $node) {
                $email = $node['email'];
                $user = User::where('email', $email)->first();
                if (!$user) {
                    Mail::to($email)->send(new AddNodeMail($resetUrl));
                }
            }
            return $this->metaSuccess();
        } catch (\Exception $ex) {
            return $this->errorResponse(__('api.error.internal_error'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * get Owner nodes
     */
    public function getOwnerNodes()
    {
        $user = auth()->user();
        $owners = OwnerNode::where('user_id', $user->id)->get();
        foreach ($owners as $owner) {
            $email = $owner->email;
            $userOwner = User::where('email', $email)->first();
            if ($userOwner) {
                $owner->kyc_verified_at = $userOwner->kyc_verified_at;
            } else {
                $owner->kyc_verified_at = null;
            }
        }
        $data = [];
        $data['kyc_verified_at'] = $user->kyc_verified_at;
        $data['owner_node'] = $owners;

        return $this->successResponse($data);
    }

    public function resendEmailOwnerNodes(ResendEmailRequest $request)
    {
        $user = auth()->user();
        $email = $request->email;
        $owners = OwnerNode::where('user_id', $user->id)->where('email', $email)->first();
        if ($owners) {
            $userOwner = User::where('email', $email)->first();
            if (!$userOwner) {
                $url = $request->header('origin') ?? $request->root();
                $resetUrl = $url . '/register-type';
                Mail::to($email)->send(new AddNodeMail($resetUrl));
            }
        } else {
            return $this->errorResponse('Email does not exist', Response::HTTP_BAD_REQUEST);
        }
        return $this->successResponse(null);
    }

    // Save Shuftipro Temp
    public function saveShuftiproTemp(Request $request)
    {
        $user = auth()->user();
        // Validator
        $validator = Validator::make($request->all(), [
            'reference_id' => 'required'
        ]);
        if ($validator->fails()) {
            return $this->validateResponse($validator->errors());
        }

        $user_id = $user->id;
        $reference_id = $request->reference_id;

        ShuftiproTemp::where('user_id', $user_id)->delete();

        $record = new ShuftiproTemp;
        $record->user_id = $user_id;
        $record->reference_id = $reference_id;
        $record->save();

        return $this->metaSuccess();
    }

    // Update Shuftipro Temp Status
    public function updateShuftiProTemp(Request $request)
    {
        $user = auth()->user();
        // Validator
        $validator = Validator::make($request->all(), [
            'reference_id' => 'required'
        ]);
        if ($validator->fails()) {
            return $this->validateResponse($validator->errors());
        }

        $user_id = $user->id;
        $reference_id = $request->reference_id;

        $record = ShuftiproTemp::where('user_id', $user_id)
            ->where('reference_id', $reference_id)
            ->first();
        if ($record) {
            $record->status = 'booked';
            $record->save();
            // check shuftipro
            $shuftiproCheck = new ShuftiproCheck();
            $shuftiproCheck->handle($record);
            return $this->metaSuccess();
        }

        return $this->errorResponse('Fail submit AML', Response::HTTP_BAD_REQUEST);
    }

    // Update Shuftipro Temp Status
    public function updateTypeOwnerNode(Request $request)
    {
        $user = auth()->user();
        // Validator
        $validator = Validator::make($request->all(), [
            'type' => [
                'required',
                Rule::in([1, 2]),
            ],
        ]);
        if ($validator->fails()) {
            return $this->validateResponse($validator->errors());
        }
        if ($user->profile) {
            $user->profile->type_owner_node = $request->type;
            $user->profile->save();
            if ($request->type == 1) {
                $user->kyc_verified_at = now();
                $user->save();
            }
            return $this->metaSuccess();
        }
        return $this->errorResponse('Fail update type', Response::HTTP_BAD_REQUEST);
    }
}
