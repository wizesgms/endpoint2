<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

use App\Models\GamesHistory;
use Illuminate\Support\Facades\DB;
use App\Models\UserPlayer;
use App\Models\AgentApi;
use App\Models\GameList;
use App\Models\ProviderList;
use Illuminate\Support\Str;

use App\Models\ApiActive;
use App\Models\ApiProvider;

use Yajra\DataTables\Facades\DataTables;

class ApiController extends Controller
{

    public function methode(Request $request)
    {
        $data = json_decode(file_get_contents('php://input'), true);
        $method = $data['method'];

        if (!$data['agent_code']) {
            return response()->json([
                'status' => 0,
                'msg' => 'INVALID_PARAMETER'
            ], 200);
        } elseif (!$data['agent_token']) {
            return response()->json([
                'status' => 0,
                'msg' => 'INVALID_PARAMETER'
            ], 200);
        }
        $agentapi = DB::table('agents')->where('agentCode', $data['agent_code'])->first();
        if (!$agentapi) {
            return response()->json([
                'status' => 0,
                'msg' => 'INVALID_AGENT'
            ], 422);
        } elseif ($data['agent_token'] !== $agentapi->token) {
            return response()->json([
                'status' => 0,
                'msg' => 'INVALID_AGENT_TOKEN'
            ], 200);
        } elseif ($agentapi->status !== 1) {
            return response()->json([
                'status' => 0,
                'msg' => 'BLOCKED_AGENT'
            ], 200);
        }

        switch ($method) {
            case 'user_create':
                return $this->PlayerAccountCreate($data);
                break;
            case 'money_info':
                return $this->getBalance($data);
                break;
            case 'user_deposit':
                return $this->balanceTopup($data);
                break;
            case 'user_withdraw':
                return $this->balanceWithdraw($data);
                break;
            case 'get_game_log':
                return $this->getHistory($data);
                break;
            case 'game_launch':
                return $this->launch_game($data);
                break;
            case 'provider_list':
                return $this->provider_list($data);
                break;
            case 'game_list':
                return $this->game_list($data);
                break;
            default:
                abort(404);
        }
    }

    function PlayerAccountCreate($data)
    {
        if (!$data['user_code']) {
            return response()->json([
                'status' => 0,
                'msg' => 'INVALID_PARAMETER'
            ], 200);
        }

        $player = DB::table('users')->where('userCode', $data['user_code'])->where('agentCode', $data['agent_code'])->first();

        if (!empty($player)) {
            return response()->json([
                'status' => 0,
                'msg' => 'DUPLICATED_USER'
            ], 200);
        }

        DB::table('users')->insert([
            'agentCode' => $data['agent_code'],
            'userCode' => $data['user_code'],
            'targetRtp' => 80,
            'realRtp' => 80,
            'balance' => 0,
            'aasUserCode' => $data['user_code'],
            'status' => 1,
            'parentPath' => 1,
            'totalDebit' => 0,
            'totalCredit' => 0,
            'apiType' => 1,
            'updatedAt' => date("Y-m-d H:i:s"),
            'createdAt' => date("Y-m-d H:i:s")
        ]);

        return response()->json([
            'status' => 1,
            'msg' => 'SUCCESS',
            'user_code' => $data['user_code'],
            'user_balance' => 0
        ], 200);
    }

    function getBalance($data)
    {

        $agents = DB::table('agents')->where('agentCode', $data['agent_code'])->first();

        if (!$data['user_code']) {
            return response()->json([
                'status' => 1,
                'msg' => 'SUCCESS',
                'agent' => [
                    'agent_code' => $data['agent_code'],
                    'agent_code' => $agents->balance
                ]
            ], 200);
        }

        $player = DB::table('users')->where('userCode', $data['user_code'])->where('agentCode', $data['agent_code'])->first();

        if (!$player) {
            return response()->json([
                'status' => 0,
                'msg' => 'INVALID_USER'
            ], 200);
        }

        return response()->json([
            'status' => 1,
            'msg' => 'SUCCESS',
            'agent' => [
                'agent_code' => $data['agent_code'],
                'balance' => $agents->balance
            ],
            'user' => [
                'user_code' => $data['user_code'],
                'balance' => $player->balance
            ]
        ], 200);
    }

    function balanceTopup($data)
    {
        if (!$data['user_code']) {
            return response()->json([
                'status' => 0,
                'msg' => 'INVALID_PARAMETER'
            ], 200);
        }

        $player = DB::table('users')->where('userCode', $data['user_code'])->where('agentCode', $data['agent_code'])->first();

        if (!$player) {
            return response()->json([
                'status' => 0,
                'msg' => 'INVALID_USER'
            ], 200);
        }

        $agents = DB::table('agents')->where('agentCode', $data['agent_code'])->first();

        if ($agents->balance < $data['amount']) {
            return response()->json([
                'status' => 0,
                'msg' => 'INSUFFICIENT_AGENT_FUNDS'
            ], 200);
        }

        $agent_balance = $agents->balance + $data['amount'];

        DB::table('agents')->where('agentCode', $data['agent_code'])->update([
            'balance' => $agent_balance,
        ]);

        $player_balance = $player->balance - $data->amount;

        DB::table('users')->where('userCode', $data['user_code'])->where('agentCode', $data['agent_code'])->update([
            'balance' => $player_balance,
        ]);

        return response()->json([
            'status' => 1,
            'msg' => 'SUCCESS',
            'agent_balance' => $agents->balance,
            'user_balance' => $player->balance
        ], 200);
    }

    function balanceWithdraw($data)
    {
        if (!$data['user_code']) {
            return response()->json([
                'status' => 0,
                'msg' => 'INVALID_PARAMETER'
            ], 200);
        }

        $player = DB::table('users')->where('userCode', $data['user_code'])->where('agentCode', $data['agent_code'])->first();

        if (!$player) {
            return response()->json([
                'status' => 0,
                'msg' => 'INVALID_USER'
            ], 200);
        }

        if ($player->balance < $data['amount']) {
            return response()->json([
                'status' => 0,
                'msg' => 'INSUFFICIENT_USER_FUNDS'
            ], 200);
        }

        $agents = DB::table('agents')->where('agentCode', $data['agent_code'])->first();
        $agent_balance = $agents->balance + $data['amount'];

        DB::table('agents')->where('agentCode', $data['agent_code'])->update([
            'balance' => $agent_balance,
        ]);

        $player_balance = $player->balance - $data->amount;

        DB::table('users')->where('userCode', $data['user_code'])->where('agentCode', $data['agent_code'])->update([
            'balance' => $player_balance,
        ]);

        return response()->json([
            'status' => 1,
            'msg' => 'SUCCESS',
            'agent_balance' => $agents->balance,
            'user_balance' => $player->balance
        ], 200);
    }

    function launch_game($data)
    {
        if (!$data['user_code']) {
            return response()->json([
                'status' => 0,
                'msg' => 'INVALID_PARAMETER'
            ], 200);
        }

        $apis = ApiProvider::first();
        $player = DB::table('users')->where('userCode', $data['user_code'])->where('agentCode', $data['agent_code'])->first();
        $agentapi = DB::table('agents')->where('agentCode', $data['agent_code'])->first();

        if (!$player) {
            return response()->json([
                'status' => 0,
                'msg' => 'INVALID_USER'
            ], 200);
        }

        $postArrayss = [
            'OperatorCode' => $apis->apikey,
            'MemberName' => $player->user_code,
            'Password' => $player->user_code,
            'GameID' => $data['game_code'],
            'ProductID' => $data['provider_code'],
            'GameType' => $data['game_type'],
            'LanguageCode' => 1,
            'Platform' => 0,
            'OperatorLobbyURL' => 'https://domain.com/',
            'Sign' => $this->generateSign($apis->apikey, date('YMdHms'), 'launchgame', $apis->secretkey),
            'RequestTime' => date('YMdHms')
        ];

        $jsonData = json_encode($postArrayss);
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $apis->url . 'Seamless/LaunchGame',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $jsonData,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json'
            ),
        ));

        $response = curl_exec($curl);
        curl_close($curl);
        $result = json_decode($response);

        if ($result->ErrorCode == 0) {
            return response()->json([
                'status' => 1,
                'msg' => 'SUCCESS',
                'launch_url' => $result->Url
            ], 200);
        } else {
            return response()->json([
                'status' => 0,
                'msg' => 'INTERNAL_ERROR'
            ], 200);
        }
    }

    function game_list($data)
    {
        $apis = ApiProvider::first();

        $postArrayss = [
            'OperatorCode' => $apis->apikey,
            'MemberName' => 'test123',
            'Password' =>  'test123',
            'ProductID' => $data['provider_code'],
            'GameType' => $data['game_type'],
            'LanguageCode' => 1,
            'Platform' => 0,
            'Sign' => $this->generateSign($apis->apikey, date('YMdHms'), 'getgamelist', $apis->secretkey),
            'RequestTime' => date('YMdHms')
        ];

        $jsonData = json_encode($postArrayss);
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $apis->url . 'Seamless/GetGameList',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $jsonData,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json'
            ),
        ));

        $response = curl_exec($curl);
        curl_close($curl);
        $result = json_decode($response);

        return $result;
    }

    function generateSign($OperatorCode, $RequestTime, $MethodName, $SecretKey)
    {
        $sign = md5($OperatorCode . $RequestTime . $MethodName . $SecretKey);
        return $sign;
    }

    function provider_list($data)
    {

        $data = json_decode(file_get_contents('https://3xplay.co/provider.json'), true);
        return $data;
    }
}
