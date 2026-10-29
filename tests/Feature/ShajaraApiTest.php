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

    public function test_link_existing_person_as_parent_or_spouse(): void
    {
        $h = $this->dev(1, 'Ilhom');

        $r = $this->postJson('/api/tree', ['name' => 'Ilhom', 'g' => 'm'], $h)->assertCreated();
        $myId = $r->json('state.my_person_id');
        $r = $this->postJson('/api/persons', [
            'role' => 'father', 'base_id' => $myId, 'name' => 'Baxtiyor', 'g' => 'm', 'alive' => true,
        ], $h)->assertCreated();
        $dadId = collect($r->json('state.persons'))->firstWhere('name', 'Baxtiyor')['id'];
        // Otaga xotin (alohida odam sifatida) qo'shildi
        $r = $this->postJson('/api/persons', [
            'role' => 'spouse', 'base_id' => $dadId, 'name' => 'Oynisa', 'g' => 'f', 'alive' => true,
        ], $h)->assertCreated();
        $momId = collect($r->json('state.persons'))->firstWhere('name', 'Oynisa')['id'];

        // Endi o'sha xotinni yangi karta yaratmasdan ONA sifatida bog'laymiz
        $r = $this->postJson('/api/persons/'.$myId.'/link', ['role' => 'mother', 'other_id' => $momId], $h)
            ->assertOk();
        $this->assertSame($momId, $r->json('state.persons.'.$myId.'.motherId'));
        $this->assertCount(3, $r->json('state.persons')); // yangi odam yaratilmadi

        // Ikkinchi marta ona bog'lab bo'lmaydi
        $this->postJson('/api/persons/'.$myId.'/link', ['role' => 'mother', 'other_id' => $momId], $h)
            ->assertStatus(422);

        // Halqa taqiqlanadi: farzandni otasiga "ota" qilib bo'lmaydi
        $this->postJson('/api/persons/'.$dadId.'/link', ['role' => 'father', 'other_id' => $myId], $h)
            ->assertStatus(422);

        // Spouse bog'lash: farzand orqali topilgan ota-onani juft qilish
        $r = $this->postJson('/api/persons/'.$dadId.'/link', ['role' => 'spouse', 'other_id' => $momId], $h)
            ->assertOk();
        $this->assertContains($momId, $r->json('state.persons.'.$dadId.'.spouseIds'));
    }

    public function test_owner_can_remove_member_and_rotate_invite_code(): void
    {
        $a = $this->dev(1, 'Ilhom');
        $b = $this->dev(2, 'Begona');
        $c = $this->dev(3, 'Olim');

        $r = $this->postJson('/api/tree', ['name' => 'Ilhom', 'g' => 'm'], $a)->assertCreated();
        $code = $r->json('invite_code');
        $this->assertTrue($r->json('is_owner'));

        // B qo'shiladi va o'ziga karta yaratib egallaydi
        $r = $this->postJson('/api/tree/join', ['code' => $code], $b)->assertCreated();
        $this->assertFalse($r->json('is_owner'));
        $this->assertNull($r->json('members')); // a'zolar ro'yxati faqat egaga

        // Ega a'zolarni ko'radi
        $r = $this->getJson('/api/state', $a)->assertOk();
        $members = collect($r->json('members'));
        $this->assertCount(2, $members);
        $bMember = $members->firstWhere('self', false);

        // B egani chiqara olmaydi (403)
        $aMember = $members->firstWhere('self', true);
        $this->postJson('/api/tree/rotate-code', [], $b)->assertStatus(403);
        $this->deleteJson('/api/tree/members/'.$aMember['id'], [], $b)->assertStatus(403);

        // Ega o'zini chiqara olmaydi
        $this->deleteJson('/api/tree/members/'.$aMember['id'], [], $a)->assertStatus(422);

        // Ega B'ni chiqaradi — B endi daraxtni ko'rmaydi
        $this->deleteJson('/api/tree/members/'.$bMember['id'], [], $a)->assertOk();
        $r = $this->getJson('/api/state', $b)->assertOk();
        $this->assertFalse($r->json('in_tree'));

        // Havolani yangilash — eski kod bilan endi kirib bo'lmaydi
        $r = $this->postJson('/api/tree/rotate-code', [], $a)->assertOk();
        $newCode = $r->json('invite_code');
        $this->assertNotSame($code, $newCode);
        $this->postJson('/api/tree/join', ['code' => $code], $c)->assertStatus(404);
        $this->postJson('/api/tree/join', ['code' => $newCode], $c)->assertCreated();
    }

    public function test_leave_tree_flows(): void
    {
        $a = $this->dev(1, 'Ilhom');
        $b = $this->dev(2, 'Bakhrom');

        // A daraxt yaratadi, B qo'shiladi
        $r = $this->postJson('/api/tree', ['name' => 'Ilhom', 'g' => 'm'], $a)->assertCreated();
        $code = $r->json('invite_code');
        $this->postJson('/api/tree/join', ['code' => $code], $b)->assertCreated();

        // Ega (boshqa a'zo bor) chiqa olmaydi
        $this->postJson('/api/tree/leave', [], $a)->assertStatus(422);

        // B chiqadi — daraxt A'da qoladi, B esa endi tashqarida
        $this->postJson('/api/tree/leave', [], $b)->assertOk()->assertJsonPath('in_tree', false);
        $this->getJson('/api/state', $b)->assertOk()->assertJsonPath('in_tree', false);
        $this->getJson('/api/state', $a)->assertOk()->assertJsonPath('in_tree', true);

        // B o'ziga daraxt ochadi, keyin o'chirib, A'ning daraxtiga qo'shila oladi
        $this->postJson('/api/tree', ['name' => 'Bakhrom', 'g' => 'm'], $b)->assertCreated();
        $this->postJson('/api/tree/join', ['code' => $code], $b)->assertStatus(422); // boshqa daraxtda
        $this->postJson('/api/tree/leave', [], $b)->assertOk(); // yolg'iz ega — daraxti o'chadi
        $this->assertSame(1, \App\Models\Tree::count());
        $this->postJson('/api/tree/join', ['code' => $code], $b)->assertCreated();
    }

    public function test_admin_panel(): void
    {
        config(['services.admin.key' => 'test-admin-key']);
        $ok = ['X-Admin-Key' => 'test-admin-key'];

        // Kalitsiz yoki noto'g'ri kalit — 403
        $this->getJson('/api/admin/overview')->assertStatus(403);
        $this->getJson('/api/admin/overview', ['X-Admin-Key' => 'xato'])->assertStatus(403);

        // Ma'lumot tayyorlaymiz
        $this->postJson('/api/tree', ['name' => 'Ilhom', 'g' => 'm'], $this->dev(1, 'Ilhom'))->assertCreated();

        $r = $this->getJson('/api/admin/overview', $ok)->assertOk();
        $this->assertSame(1, $r->json('foydalanuvchilar'));
        $this->assertSame(1, $r->json('daraxtlar'));

        $r = $this->getJson('/api/admin/users', $ok)->assertOk();
        $this->assertSame('Ilhom', $r->json('users.0.ism'));
        $this->assertStringContainsString('ega', $r->json('users.0.daraxt'));

        $this->getJson('/api/admin/trees', $ok)->assertOk()->assertJsonPath('trees.0.ega', 'Ilhom');

        // Daraxtni ko'rish (holat) — kalit bilan, yo'q id'ga 404
        $tid = $this->getJson('/api/admin/trees', $ok)->json('trees.0.id');
        $rs = $this->getJson('/api/admin/trees/'.$tid.'/state', $ok)->assertOk();
        $this->assertTrue($rs->json('in_tree'));
        $this->assertNotEmpty($rs->json('state.persons'));
        $this->getJson('/api/admin/trees/99999/state', $ok)->assertStatus(404);
        $this->getJson('/api/admin/trees/'.$tid.'/state')->assertStatus(403);

        // Alohida xabar
        \Illuminate\Support\Facades\Http::fake([
            '*' => \Illuminate\Support\Facades\Http::response(['ok' => true]),
        ]);
        $uid = $r->json('users.0.id');
        $this->postJson('/api/admin/message', ['user_id' => $uid, 'text' => 'Salom!'], $ok)
            ->assertOk()->assertJsonPath('sent', 1);

        // Ommaviy xabar
        $this->postJson('/api/tree', ['name' => 'Olim', 'g' => 'm'], $this->dev(2, 'Olim'));
        $r = $this->postJson('/api/admin/broadcast', ['text' => 'Yangilik!'], $ok)->assertOk();
        $this->assertSame(2, $r->json('sent'));
        \Illuminate\Support\Facades\Http::assertSentCount(3); // 1 message + 2 broadcast
    }

    public function test_mobile_auth_flow(): void
    {
        config(['services.telegram.webhook_secret' => 'test-secret']);
        \Illuminate\Support\Facades\Http::fake();

        // 1. Mobil ilova kod so'raydi
        $r = $this->postJson('/api/auth/request', ['device' => 'iPhone 15'])->assertCreated();
        $code = $r->json('code');
        $this->assertStringContainsString('start=auth_'.$code, $r->json('deep_link'));

        // Hali bog'lanmagan — pending
        $this->getJson('/api/auth/token?code='.$code)->assertOk()->assertJsonPath('status', 'pending');

        // 2. Foydalanuvchi botda Start bosadi (webhook)
        $this->postJson('/api/telegram/webhook', [
            'message' => [
                'chat' => ['id' => 777, 'type' => 'private'],
                'from' => ['id' => 777, 'first_name' => 'Mobil'],
                'text' => '/start auth_'.$code,
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();

        // 3. Token olinadi (faqat bir marta)
        $r = $this->getJson('/api/auth/token?code='.$code)->assertOk();
        $this->assertSame('ok', $r->json('status'));
        $token = $r->json('token');
        $this->getJson('/api/auth/token?code='.$code)->assertStatus(410);

        // 4. Bearer bilan API ishlaydi
        $this->getJson('/api/state', ['Authorization' => 'Bearer '.$token])
            ->assertOk()->assertJsonPath('in_tree', false);
        $this->postJson('/api/tree', ['name' => 'Mobil', 'g' => 'm'],
            ['Authorization' => 'Bearer '.$token])->assertCreated();

        // Noto'g'ri token — 401
        $this->getJson('/api/state', ['Authorization' => 'Bearer notogri'])->assertStatus(401);

        // Eskirgan kod — 404
        $this->getJson('/api/auth/token?code=YOQKOD99')->assertStatus(404);
    }

    public function test_person_photos(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $h = $this->dev(1, 'Ilhom');
        $r = $this->postJson('/api/tree', ['name' => 'Ilhom', 'g' => 'm'], $h)->assertCreated();
        $myId = $r->json('state.my_person_id');

        // Ikki rasm yuklanadi, holatda URL bilan qaytadi
        $f1 = \Illuminate\Http\UploadedFile::fake()->image('r1.jpg', 100, 100);
        $r = $this->post('/api/persons/'.$myId.'/photos', ['photo' => $f1],
            ['X-Dev-User' => '1:Ilhom', 'Accept' => 'application/json'])->assertCreated();
        $f2 = \Illuminate\Http\UploadedFile::fake()->image('r2.png', 80, 80);
        $r = $this->post('/api/persons/'.$myId.'/photos', ['photo' => $f2],
            ['X-Dev-User' => '1:Ilhom', 'Accept' => 'application/json'])->assertCreated();
        $photos = $r->json('state.persons.'.$myId.'.photos');
        $this->assertCount(2, $photos);
        $this->assertStringContainsString('/storage/persons/', $photos[0]['url']);

        // Rasm bo'lmagan fayl rad etiladi
        $bad = \Illuminate\Http\UploadedFile::fake()->create('x.pdf', 10, 'application/pdf');
        $this->post('/api/persons/'.$myId.'/photos', ['photo' => $bad],
            ['X-Dev-User' => '1:Ilhom', 'Accept' => 'application/json'])->assertStatus(422);

        // O'chirish
        $r = $this->deleteJson('/api/photos/'.$photos[0]['id'], [], $h)->assertOk();
        $this->assertCount(1, $r->json('state.persons.'.$myId.'.photos'));

        // Boshqa foydalanuvchi (boshqa daraxt) o'chira olmaydi
        $this->postJson('/api/tree', ['name' => 'Begona', 'g' => 'm'], $this->dev(2, 'Begona'));
        $this->deleteJson('/api/photos/'.$photos[1]['id'], [], $this->dev(2, 'Begona'))->assertStatus(404);
    }

    public function test_bio_and_pdf_export(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $h = $this->dev(1, 'Ilhom');
        $r = $this->postJson('/api/tree', ['name' => 'Ilhom', 'g' => 'm'], $h)->assertCreated();
        $myId = $r->json('state.my_person_id');

        // Bio yoziladi va holatda qaytadi
        $r = $this->patchJson('/api/persons/'.$myId, [
            'name' => 'Ilhom', 'g' => 'm', 'alive' => true,
            'bio' => "Dasturchi. Shajara loyihasi asoschisi.",
        ], $h)->assertOk();
        $this->assertSame('Dasturchi. Shajara loyihasi asoschisi.',
            $r->json('state.persons.'.$myId.'.bio'));

        // Bo'sh bio — null bo'lib tozalanadi
        $r = $this->patchJson('/api/persons/'.$myId, [
            'name' => 'Ilhom', 'g' => 'm', 'alive' => true, 'bio' => '   ',
        ], $h)->assertOk();
        $this->assertNull($r->json('state.persons.'.$myId.'.bio'));

        // PDF eksport: saqlash → bir martalik yuklab olish
        $pdf = "%PDF-1.4 test ".str_repeat('x', 200);
        $r = $this->call('POST', '/api/export', [], [], [],
            ['CONTENT_TYPE' => 'application/pdf', 'HTTP_X_DEV_USER' => '1:Ilhom'], $pdf);
        $r->assertOk();
        $path = parse_url($r->json('url'), PHP_URL_PATH);
        $this->get($path)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        // Telegram yuklab olishdan oldin URL'ni bir necha marta so'raydi —
        // havola amal qilish muddatida qayta ishlashi SHART
        $this->get($path)->assertOk();
        $this->get('/export/'.str_repeat('x', 40))->assertNotFound();

        // PDF bo'lmagan kontent rad etiladi
        $this->call('POST', '/api/export', [], [], [],
            ['CONTENT_TYPE' => 'application/pdf', 'HTTP_X_DEV_USER' => '1:Ilhom'], str_repeat('a', 200))
            ->assertStatus(422);
    }

    public function test_telegram_webhook_replies_with_app_button(): void
    {
        config(['services.telegram.webhook_secret' => 'test-secret']);
        \Illuminate\Support\Facades\Http::fake();

        $this->postJson('/api/telegram/webhook', [
            'message' => ['chat' => ['id' => 55, 'type' => 'private'], 'text' => '/start'],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();

        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => str_contains($r->url(), '/sendMessage')
            && $r['chat_id'] === 55
            && str_contains($r['reply_markup']['inline_keyboard'][0][0]['url'] ?? '', 't.me/'));

        // Noto'g'ri secret — rad etiladi
        $this->postJson('/api/telegram/webhook', [
            'message' => ['chat' => ['id' => 55, 'type' => 'private'], 'text' => 'salom'],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'boshqa'])->assertStatus(403);

        // Guruh xabariga javob yuborilmaydi
        \Illuminate\Support\Facades\Http::fake();
        $this->postJson('/api/telegram/webhook', [
            'message' => ['chat' => ['id' => -100, 'type' => 'group'], 'text' => '/start'],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_personal_invite_preview_and_join(): void
    {
        $a = $this->dev(1, 'Ilhom');
        $b = $this->dev(2, 'Sardor');
        $c = $this->dev(3, 'Olim');

        $r = $this->postJson('/api/tree', ['name' => 'Ilhom', 'g' => 'm'], $a)->assertCreated();
        $code = $r->json('invite_code');
        $myId = $r->json('state.my_person_id');
        $this->postJson('/api/persons', [
            'role' => 'father', 'base_id' => $myId, 'name' => 'Baxtiyor', 'g' => 'm', 'alive' => true,
        ], $a)->assertCreated();
        $r = $this->postJson('/api/persons', [
            'role' => 'sibling', 'base_id' => $myId, 'name' => 'Sardor', 'g' => 'm', 'by' => 1988, 'alive' => true,
        ], $a)->assertCreated();
        $sardorId = collect($r->json('state.persons'))->firstWhere('name', 'Sardor')['id'];

        // Shaxsiy preview: karta ma'lumoti chiqadi, hali band emas
        $this->getJson('/api/invites/'.$code.'?person='.$sardorId, $b)
            ->assertOk()
            ->assertJsonPath('person.name', 'Sardor')
            ->assertJsonPath('person.claimed', false);

        // Boshqa daraxt kartasi / yo'q karta — person chiqmaydi
        $this->getJson('/api/invites/'.$code.'?person=p99999', $b)
            ->assertOk()
            ->assertJsonMissingPath('person');

        // Shaxsiy havola bilan qo'shilish — karta darhol egallanadi
        $r = $this->postJson('/api/tree/join', ['code' => $code, 'person_id' => $sardorId], $b)
            ->assertCreated();
        $this->assertSame($sardorId, $r->json('state.my_person_id'));

        // Endi o'sha karta band: preview'da ko'rinadi, join'da ogohlantirish bilan egallanmaydi
        $this->getJson('/api/invites/'.$code.'?person='.$sardorId, $c)
            ->assertOk()
            ->assertJsonPath('person.claimed', true);
        $r = $this->postJson('/api/tree/join', ['code' => $code, 'person_id' => $sardorId], $c)
            ->assertCreated();
        $this->assertNull($r->json('state.my_person_id'));
        $this->assertNotNull($r->json('notice'));
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
