<?php

namespace Tests\Feature;

use App\Http\Middleware\TelegramAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShajaraApiTest extends TestCase
{
    use RefreshDatabase;

    private const BOT_TOKEN = '1234567:TEST_TOKEN_abcdef';

    /** Lokal sinov foydalanuvchisi sarlavhasi. */
    private function dev(int $id, string $name): array
    {
        return ['X-Dev-User' => $id.':'.$name, 'Accept' => 'application/json'];
    }

    /** Telegram imzosi bilan haqiqiy initData satrini quradi. */
    private function makeInitData(array $user, ?string $startParam = null, ?int $authDate = null): string
    {
        $data = [
            'auth_date' => (string) ($authDate ?? time()),
            'query_id' => 'AAATest',
            'user' => json_encode($user, JSON_UNESCAPED_UNICODE),
        ];
        if ($startParam !== null) {
            $data['start_param'] = $startParam;
        }
        ksort($data);
        $checkString = implode("\n", array_map(fn ($k) => $k.'='.$data[$k], array_keys($data)));
        $secretKey = hash_hmac('sha256', self::BOT_TOKEN, 'WebAppData', true);
        $data['hash'] = hash_hmac('sha256', $checkString, $secretKey);

        return http_build_query($data);
    }

    public function test_auth_is_required(): void
    {
        $this->getJson('/api/state')->assertStatus(401);
    }

    public function test_valid_initdata_is_accepted_and_user_upserted(): void
    {
        config(['services.telegram.bot_token' => self::BOT_TOKEN]);
        $initData = $this->makeInitData(['id' => 777, 'first_name' => 'Ilhom', 'username' => 'ilhom']);

        $this->getJson('/api/state', ['Authorization' => 'tma '.$initData])
            ->assertOk()
            ->assertJson(['in_tree' => false]);

        $this->assertDatabaseHas('users', ['tg_id' => 777, 'first_name' => 'Ilhom']);
    }

    public function test_tampered_initdata_is_rejected(): void
    {
        config(['services.telegram.bot_token' => self::BOT_TOKEN]);
        $initData = $this->makeInitData(['id' => 777, 'first_name' => 'Ilhom']);
        $tampered = str_replace('Ilhom', 'Xaker', $initData);

        $this->getJson('/api/state', ['Authorization' => 'tma '.$tampered])->assertStatus(401);
    }

    public function test_stale_initdata_is_rejected(): void
    {
        config(['services.telegram.bot_token' => self::BOT_TOKEN]);
        $old = $this->makeInitData(['id' => 777, 'first_name' => 'Ilhom'], null, time() - 90000);

        $this->getJson('/api/state', ['Authorization' => 'tma '.$old])->assertStatus(401);
    }

    public function test_create_tree_and_add_relatives(): void
    {
        $h = $this->dev(1, 'Ilhom');

        $r = $this->postJson('/api/tree', ['name' => 'Ilhom', 'g' => 'm', 'by' => 1992], $h)
            ->assertCreated()
            ->assertJsonPath('in_tree', true);
        $myId = $r->json('state.my_person_id');
        $this->assertNotNull($myId);
        $this->assertSame($r->json('state.root_id'), $myId);
        $this->assertTrue($r->json("state.persons.$myId.claimed"));

        // Ota qo'shish
        $r = $this->postJson('/api/persons', [
            'role' => 'father', 'base_id' => $myId, 'name' => 'Baxtiyor', 'g' => 'm', 'by' => 1965, 'alive' => true,
        ], $h)->assertCreated();
        $fatherId = $r->json("state.persons.$myId.fatherId");
        $this->assertNotNull($fatherId);

        // Ikkinchi marta ota qo'shib bo'lmaydi
        $this->postJson('/api/persons', [
            'role' => 'father', 'base_id' => $myId, 'name' => 'Boshqa', 'g' => 'm', 'alive' => true,
        ], $h)->assertStatus(422);

        // Ona qo'shilganda ota bilan er-xotin bog'i paydo bo'ladi
        $r = $this->postJson('/api/persons', [
            'role' => 'mother', 'base_id' => $myId, 'name' => 'Gulnora', 'g' => 'f', 'by' => 1968, 'alive' => true,
        ], $h)->assertCreated();
        $motherId = $r->json("state.persons.$myId.motherId");
        $this->assertContains($motherId, $r->json("state.persons.$fatherId.spouseIds"));

        // Turmush o'rtoq va farzand
        $r = $this->postJson('/api/persons', [
            'role' => 'spouse', 'base_id' => $myId, 'name' => 'Madina', 'g' => 'f', 'alive' => true,
        ], $h)->assertCreated();
        $spouseId = $r->json("state.persons.$myId.spouseIds.0");
        $this->assertNotNull($spouseId);

        $r = $this->postJson('/api/persons', [
            'role' => 'child', 'base_id' => $myId, 'other_parent_id' => $spouseId,
            'name' => 'Amir', 'g' => 'm', 'by' => 2018, 'alive' => true,
        ], $h)->assertCreated();
        $persons = $r->json('state.persons');
        $child = collect($persons)->firstWhere('name', 'Amir');
        $this->assertSame($myId, $child['fatherId']);
        $this->assertSame($spouseId, $child['motherId']);

        $this->assertCount(5, $persons);
    }

    public function test_join_via_code_and_claim(): void
    {
        $a = $this->dev(1, 'Ilhom');
        $b = $this->dev(2, 'Sardor');

        $r = $this->postJson('/api/tree', ['name' => 'Ilhom', 'g' => 'm'], $a)->assertCreated();
        $code = $r->json('invite_code');
        $myId = $r->json('state.my_person_id');
        $this->postJson('/api/persons', [
            'role' => 'sibling', 'base_id' => $myId, 'name' => 'Sardor', 'g' => 'm', 'alive' => true,
        ], $a)->assertStatus(422); // ota-onasiz aka-uka bo'lmaydi
        $this->postJson('/api/persons', [
            'role' => 'father', 'base_id' => $myId, 'name' => 'Baxtiyor', 'g' => 'm', 'alive' => true,
        ], $a)->assertCreated();
        $r = $this->postJson('/api/persons', [
            'role' => 'sibling', 'base_id' => $myId, 'name' => 'Sardor', 'g' => 'm', 'by' => 1988, 'alive' => true,
        ], $a)->assertCreated();
        $sardorId = collect($r->json('state.persons'))->firstWhere('name', 'Sardor')['id'];

        // Taklifni ko'rish va qo'shilish
        $this->getJson('/api/invites/'.$code, $b)
            ->assertOk()
            ->assertJsonPath('owner_name', 'Ilhom');
        $r = $this->postJson('/api/tree/join', ['code' => $code], $b)->assertCreated();
        $this->assertNull($r->json('state.my_person_id'));
        $this->assertCount(3, $r->json('state.persons'));

        // Sardor o'z kartasini belgilaydi
        $r = $this->postJson('/api/persons/'.$sardorId.'/claim', [], $b)->assertOk();
        $this->assertSame($sardorId, $r->json('state.my_person_id'));

        // Ilhom endi o'sha kartani egallay olmaydi
        $this->postJson('/api/persons/'.$sardorId.'/claim', [], $a)->assertStatus(422);

        // Noto'g'ri kod
        $this->postJson('/api/tree/join', ['code' => 'YOQKOD22'], $this->dev(3, 'Test'))->assertStatus(404);
    }

    public function test_delete_guards(): void
    {
        $h = $this->dev(1, 'Ilhom');
        $r = $this->postJson('/api/tree', ['name' => 'Ilhom', 'g' => 'm'], $h)->assertCreated();
        $myId = $r->json('state.my_person_id');

        // O'z kartasini o'chirib bo'lmaydi (biriktirilgan)
        $this->deleteJson('/api/persons/'.$myId, [], $h)->assertStatus(422);

        // Ota + bobo: otani o'chirsak bobo uzilib qoladi — taqiqlanadi
        $r = $this->postJson('/api/persons', [
            'role' => 'father', 'base_id' => $myId, 'name' => 'Baxtiyor', 'g' => 'm', 'alive' => true,
        ], $h)->assertCreated();
        $fatherId = $r->json("state.persons.$myId.fatherId");
        $r = $this->postJson('/api/persons', [
            'role' => 'father', 'base_id' => $fatherId, 'name' => 'Jumaboy', 'g' => 'm', 'alive' => false, 'dy' => 2009,
        ], $h)->assertCreated();
        $this->deleteJson('/api/persons/'.$fatherId, [], $h)->assertStatus(422);

        // Bobo chekkada — o'chirish mumkin
        $grandId = $r->json("state.persons.$fatherId.fatherId");
        $r = $this->deleteJson('/api/persons/'.$grandId, [], $h)->assertOk();
        $this->assertCount(2, $r->json('state.persons'));
    }

    public function test_dev_bypass_disabled_in_production(): void
    {
        $this->app['env'] = 'production';
        $this->getJson('/api/state', ['X-Dev-User' => '1:Test'])->assertStatus(401);
    }

    public function test_initdata_validator_unit(): void
    {
        $this->assertNull(TelegramAuth::validateInitData('', self::BOT_TOKEN));
        $this->assertNull(TelegramAuth::validateInitData('user=x&hash=abc', self::BOT_TOKEN));
        $this->assertNull(TelegramAuth::validateInitData('user=x&hash=abc', null));

        $valid = $this->makeInitData(['id' => 5, 'first_name' => "G'ani"]);
        $parsed = TelegramAuth::validateInitData($valid, self::BOT_TOKEN);
        $this->assertNotNull($parsed);
        $this->assertSame(5, json_decode($parsed['user'], true)['id']);
    }
}
