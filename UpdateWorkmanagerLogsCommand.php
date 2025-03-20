<?php

namespace App\Console\Commands;

use Cache;
use Illuminate\Console\Command;
use Carbon\Carbon;
use App\Models\User;
use App\Models\WorkmanagerLog;
use App\Models\Attendance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Helpers\FCMHelper;
use App\Http\Controllers\WeekendControlController;

class UpdateWorkmanagerLogsCommand extends Command implements ShouldQueue
{
    // ---------------------------------------------------------
    // Sabit Tanımlamalar
    // ---------------------------------------------------------
    const ACTIVE_THRESHOLD_SECONDS = 40;
    const RESUME_CACHE_SECONDS = 360; // 6 dakika
    const EARLY_CHECKIN_START = '06:00:00';
    const EARLY_CHECKIN_END = '08:00:00';
    const MORNING_CHECKIN_START = '08:00:00';
    const MORNING_CHECKIN_END = '09:00:00';
    const STOP_CHECK_TIME = '18:05:00';
    const WORKMANAGER_ACTIVE_THRESHOLD_SECONDS = 60;
    const MAX_RESUME_ATTEMPTS = 3;
    const NO_GEO_LOG_THRESHOLD_MINUTES = 20;
    const RESUME_WAIT_HOURS = 2;
    const WORKMANAGER_CONTROL_TOLERANCE_MINUTES = 3;

    const TIMEZONE = 'Europe/Istanbul';


    // ---------------------------------------------------------
    // Komut Ayarları
    // ---------------------------------------------------------
    protected $signature = 'workmanager:updatelogs {--mode= : İşlem modu (varsayılan: normal, D: resume kontrol özet bildirimi)}';
    protected $description = 'Workmanager AI job: Kullanıcıların konum, izin/rapor durumlarına ve mesai bilgilerine göre workmanager_logs tablosunu günceller.';

    // ---------------------------------------------------------
    // Zaman Dilimi (Tüm Carbon işlemlerinde kullanılacak)
    // ---------------------------------------------------------
    protected $timezone = 'Europe/Istanbul';

    // ---------------------------------------------------------
    // handle() - Komutun Ana Giriş Noktası
    // ---------------------------------------------------------
    public function handle()
    {
        $now = Carbon::now($this->timezone); // Zaman dilimini belirtiyoruz
        $today = $now->toDateString(); // Sadece tarihi al (Y-m-d)
        $kernelLog = [];

        $this->info('Workmanager log update job başlatıldı: ' . $now->toDateTimeString());

        $users = User::where('workmanager_ai', 1)->get();
        if ($users->isEmpty()) {
            $this->info('İşlenecek kullanıcı bulunamadı.');
            return;
        }

        foreach ($users as $user) {
            try { // TÜM KULLANICI İŞLEMLERİNİ TRY-CATCH İÇİNE ALIYORUZ
                $this->processUser($user, $now, $today, $kernelLog);
            } catch (\Exception $e) {
                $logMessage = "[processUser] => User ID: {$user->id}, Hata: " . $e->getMessage();
                \Log::error($logMessage);
                $kernelLog[$user->id]['error'] = $logMessage;
            }
        }


        $jsonData = json_encode($kernelLog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $filename = 'workmanager_kernel_' . $today . '.json';
        Storage::disk('local')->put($filename, $jsonData);

        if (strtoupper($this->option('mode')) === 'D') {
            $this->sendResumeSummaryNotification($kernelLog);
        }

        // Admin bildirimlerinin gönderilmesi (Hem Resume hem de Stop için)
        $this->sendAdminNotifications($now, $kernelLog);


        $this->info('Workmanager logs update job tamamlandı.');
    }

    // ---------------------------------------------------------
    // Module B: Kullanıcı Bazlı İşlemler
    // ---------------------------------------------------------
    private function processUser($user, Carbon $now, string $today, array &$kernelLog)
    {
        $kernelLog[$user->id] = ['user' => $user->name];

        // 0) FCM Token Kontrolü
        $fcmTokens = DB::table('user_fcm_tokens')
            ->where('user_id', $user->id)
            ->pluck('fcm_token')
            ->toArray();
        if (empty($fcmTokens)) {
            $kernelLog[$user->id]['fcmToken'] = 'No FCM token found; user skipped';
            return;
        }

        // 1) İzin/Rapor Kontrolü
        if ($this->isUserOnLeave($user)) {
            $kernelLog[$user->id]['leave'] = 'User on approved leave/rapor (or inactive on weekend), processing skipped';
            return;
        }

        // 2) Özel Çalışma Saatleri Kontrolü
        if ($this->hasCustomHours($user)) {
            $kernelLog[$user->id]['customHours'] = 'User has custom hours; workmanager skipped';
            return;
        }

        // 3) Mevcut workmanager_logs kaydı
        $wmLog = WorkmanagerLog::where('user_id', $user->id)
            ->whereDate('date', $today) // ->whereDate('created_at', $today) yerine.
            ->first();
        if (!$wmLog) {
            $kernelLog[$user->id]['wmLog'] = 'Log kaydı bulunamadı, atlandı';
            return;
        }

        // 4) Geo Log Kontrolü
        $lastGeoLog = DB::table('geo_logs')
            ->where('user_id', $user->id)
            ->whereDate('created_at', $today) // ->whereDate ile sadece bugünkü kayıtlar
            ->orderBy('created_at', 'desc')
            ->first();


        if (!$lastGeoLog) {
            $kernelLog[$user->id]['geoLog'] = 'Bugüne ait geo log bulunamadı; Resume tetikleniyor';
            $this->checkWorkManagerResumeStatusDB($user, $wmLog, $kernelLog);
            return;
        }

        $currentLocation = $lastGeoLog->location ?? ($lastGeoLog->lat . ',' . $lastGeoLog->lng);

        // 5) Kullanıcının aktifliği
        $diffSeconds = Carbon::parse($lastGeoLog->created_at, $this->timezone)->diffInSeconds($now);
        $isActive = ($diffSeconds <= self::ACTIVE_THRESHOLD_SECONDS);
        $kernelLog[$user->id]['active'] = $isActive ? 'active' : 'inactive';


        if (!$isActive) {
            $kernelLog[$user->id]['action'] = 'User inactive; resume command triggered';
            $this->checkWorkManagerResumeStatusDB($user, $wmLog, $kernelLog);
            return;
        }

        // 6) Bugünkü attendance (giriş) kaydı
        $attendance = Attendance::where('user_id', $user->id)
            ->whereDate('check_in_time', $today)
            ->first();

        // 7) Sabah İşlemleri
        $this->processMorningNotifications($user, $now, $wmLog, $attendance, $currentLocation, $kernelLog);

        // 8) Akşam İşlemleri
        $this->processEveningNotifications($user, $now, $wmLog, $attendance, $currentLocation, $kernelLog);
    }

    // ---------------------------------------------------------
    // Module B: Sabah İşlemleri
    // ---------------------------------------------------------
    private function processMorningNotifications($user, Carbon $now, $wmLog, $attendance, $currentLocation, array &$kernelLog)
    {
        if ($now->between(Carbon::createFromTimeString(self::EARLY_CHECKIN_START, $this->timezone), Carbon::createFromTimeString(self::EARLY_CHECKIN_END, $this->timezone))) {
            if (!$attendance) {
                if ($this->isWithinProximity($currentLocation, $user->check_in_location)) {
                    $this->sendEarlyCheckInNotification($user);
                    $kernelLog[$user->id]['earlyPush'] = 'Early check-in push sent';
                } else {
                    $kernelLog[$user->id]['earlyPush'] = 'User not near check-in location (06:00-08:00)';
                }
            } else {
                $kernelLog[$user->id]['earlyPush'] = 'User already checked in';
            }
        }

        if (!$attendance && $now->between(Carbon::createFromTimeString(self::MORNING_CHECKIN_START, $this->timezone), Carbon::createFromTimeString(self::MORNING_CHECKIN_END, $this->timezone))) {
            if ($this->isWithinProximity($currentLocation, $user->check_in_location)) {
                if ($now->hour == 9 && $wmLog->checkGiris09 == 0) {
                    $wmLog->checkGiris09 = 1;
                    $wmLog->save();
                    $kernelLog[$user->id]['checkGiris09'] = 'Updated at 09:00';
                }
                if ($now->hour == 11 && $wmLog->checkGiris11 == 0) {
                    $wmLog->checkGiris11 = 1;
                    $wmLog->save();
                    $kernelLog[$user->id]['checkGiris11'] = 'Updated at 11:00';
                }
                if ($now->between(Carbon::createFromTime(12, 20, 0, $this->timezone), Carbon::createFromTime(12, 21, 0, $this->timezone)) && $wmLog->checkGiris12_20 == 0) {
                    $wmLog->checkGiris12_20 = 1;
                    $wmLog->save();
                    $kernelLog[$user->id]['checkGiris12_20'] = 'Updated at 12:20';
                }
            } else {
                $kernelLog[$user->id]['morningLocation'] = 'User not near check-in location';
            }
        } else {
            $kernelLog[$user->id]['morning'] = $attendance ? 'User already checked in' : 'Not in morning check-in period';
        }
    }

    // ---------------------------------------------------------
    // Module B: Akşam İşlemleri (DÜZENLENMİŞ)
    // ---------------------------------------------------------
    private function processEveningNotifications($user, Carbon $now, WorkmanagerLog $wmLog, $attendance, $currentLocation, array &$kernelLog)
    {
        // Vardiyada olan kullanıcılar için akşam bildirimleri atlanır.
        if ($this->isUserInShift($user)) {
            $kernelLog[$user->id]['evening'] = 'User in shift; evening notifications deferred';
            return;
        }

        // Kullanıcı giriş yapmış ama çıkış yapmamışsa:
        if ($attendance && !$attendance->check_out_time) {
            // 16:50 Kontrolü: Kullanıcı konumdaysa ve checkCikis1655 henüz 0 ise:
            if ($now->gte(Carbon::createFromTime(16, 50, 0, $this->timezone)) && $wmLog->checkCikis1655 == 0) {
                if ($this->isWithinProximity($currentLocation, $user->check_out_location)) {
                    $wmLog->checkCikis1655 = 1;
                    $wmLog->save();
                    $kernelLog[$user->id]['checkCikis1655'] = 'Updated at 16:50 (konumda)';
                    // Bildirim gönder (isteğe bağlı)
                } else {
                    $kernelLog[$user->id]['checkCikis1655'] = '16:50 - User NOT near check-out location';
                }
            }

            // 17:15 Kontrolü:  Kullanıcı hala çıkış yapmamış, konumda ve checkCikis1715 henüz 0 ise:
            if ($now->gte(Carbon::createFromTime(17, 15, 0, $this->timezone)) && $wmLog->checkCikis1715 == 0) {
                if ($this->isWithinProximity($currentLocation, $user->check_out_location)) {
                    $wmLog->checkCikis1715 = 1;
                    $wmLog->save();
                    $kernelLog[$user->id]['checkCikis1715'] = 'Updated at 17:15 (konumda).';
                    // Bildirim gönder (isteğe bağlı)
                } else {
                    $kernelLog[$user->id]['checkCikis1655'] = '17:15 - User NOT near check-out location';
                }
            }

            // 17:40 Kontrolü: Kullanıcı hala çıkış yapmamışsa (konumdan bağımsız) ve checkCikisAfter1740 henüz 0 ise:
            if ($now->gte(Carbon::createFromTime(17, 40, 0, $this->timezone)) && $wmLog->checkCikisAfter1740 == 0) {
                $wmLog->checkCikisAfter1740 = 1;
                $wmLog->save();
                $kernelLog[$user->id]['checkCikisAfter1740'] = 'Updated at 17:40 (konum kontrolsüz)';
                //Bildirim gönder (İsteğe bağlı.)
            }
        }
        // Kullanıcı zaten çıkış yapmışsa ve çıkıştan 2 dakika geçmişse, stop bildirimi gönder (Eski kodunla aynı mantık)
        else if ($attendance && $attendance->check_out_time) {
            $checkOutTime = Carbon::parse($attendance->check_out_time, $this->timezone);
            if ($now->diffInMinutes($checkOutTime) >= 2) {
                $kernelLog[$user->id]['stop'] = 'User stopped 2 minutes after check-out';
                $this->checkWorkManagerStopStatus($user); // Bu fonksiyonda da ufak değişiklikler yaptık.
            } else {
                $kernelLog[$user->id]['evening'] = 'User recently checked out, waiting for stop trigger';
            }
        }
        // Kullanıcı hiç giriş yapmamışsa:
        else {
            $kernelLog[$user->id]['evening'] = 'User did not check in; evening processing skipped';
        }
    }

    // ---------------------------------------------------------
    // Module A: Admin Bildirimleri (DÜZENLENMİŞ)
    // ---------------------------------------------------------
    private function sendAdminNotifications(Carbon $now, array &$kernelLog)
    {
        $admin = User::where('role', 1)->first();
        if (!$admin) {
            return;
        }

        // Günün başlangıcı ve bitişi (timezone ile)
        $today = $now->toDateString();
        $startOfDay = Carbon::createFromFormat('Y-m-d H:i:s', $today . ' 00:00:00', $this->timezone);
        $endOfDay = Carbon::createFromFormat('Y-m-d H:i:s', $today . ' 23:59:59', $this->timezone);

        // ------------------------------
        // Admin Resume Bildirimi (08:00)
        // ------------------------------
        if ($now->format('H:i') == '08:00') {  // Sadece 08:00'da çalışır
            $existing = DB::table('wm_notification_logs')
                ->where('user_id', $admin->id)
                ->where('command', 'admin_resume')
                ->whereBetween('created_at', [$startOfDay, $endOfDay])
                ->first();

            if (!$existing) {
                try {
                    $mesaiCount = 0;
                    foreach ($kernelLog as $log) {
                        if (!isset($log['evening']) && !isset($log['stop'])) {
                            $mesaiCount++;
                        }
                    }
                    $message = 'WorkManager Resume: ' . $mesaiCount . ' mesai kullanıcısı aktif.';
                    FCMHelper::sendNotification($admin, $message);

                    // LOG KAYDI (try-catch içinde)
                    try {
                        DB::table('wm_notification_logs')->insert([
                            'user_id' => $admin->id,
                            'command' => 'admin_resume',
                            'status' => 'sent',
                            'explanation' => 'Admin resume bildirimi gönderildi. Aktif mesai sayısı: ' . $mesaiCount,
                            'created_at' => $now->toDateTimeString(), // Carbon nesnesi
                            'updated_at' => $now->toDateTimeString(), // Carbon nesnesi
                        ]);
                    } catch (\Exception $e) {
                        \Log::error("[sendAdminNotifications: Admin Resume Log] => Hata: " . $e->getMessage());
                        $kernelLog['admin']['resume_log_error'] = "Admin resume log kaydı hatası: " . $e->getMessage();
                    }

                    $kernelLog['admin']['resume'] = "Admin resume bildirimi gönderildi: $message";

                } catch (\Exception $e) {
                    \Log::error("[sendAdminNotifications: Admin Resume] => Hata: " . $e->getMessage());
                    $kernelLog['admin']['resume_error'] = "Admin resume bildirimi gönderiminde hata: " . $e->getMessage();
                }
            } else {
                $kernelLog['admin']['resume'] = "Admin resume bildirimi daha önce gönderilmiş.";
            }
        }

        // ------------------------------
        // Admin Stop Bildirimi (00:00) - DÜZENLENDİ!
        // ------------------------------
        if ($now->format('H:i') == '00:00') { // SADECE GECE YARISI (00:00) ÇALIŞIR
            $existing = DB::table('wm_notification_logs')
                ->where('user_id', $admin->id)
                ->where('command', 'admin_stop')
                ->whereBetween('created_at', [$startOfDay, $endOfDay])
                ->first();

            if (!$existing) {
                try {
                    $stopCount = 0;
                    foreach ($kernelLog as $log) {
                        if (isset($log['stop'])) {
                            $stopCount++;
                        }
                    }

                    $message = 'WorkManager Stop: ' . $stopCount . ' mesai kullanıcısı durdu.';
                    FCMHelper::sendNotification($admin, $message);

                    // LOG KAYDI (try-catch içinde)
                    try {
                        DB::table('wm_notification_logs')->insert([
                            'user_id' => $admin->id,
                            'command' => 'admin_stop',
                            'status' => 'sent',
                            'explanation' => 'Admin stop bildirimi gönderildi. Durdurulan mesai sayısı: ' . $stopCount,
                            'created_at' => $now->toDateTimeString(), // Carbon nesnesi!
                            'updated_at' => $now->toDateTimeString(), // Carbon nesnesi!
                        ]);
                    } catch (\Exception $e) {
                        \Log::error("[sendAdminNotifications: Admin Stop Log] => Hata: " . $e->getMessage());
                        $kernelLog['admin']['stop_log_error'] = "Admin stop log kaydı hatası: " . $e->getMessage();
                    }

                    $kernelLog['admin']['stop'] = "Admin stop bildirimi gönderildi: $message";

                } catch (\Exception $e) {
                    \Log::error("[sendAdminNotifications: Admin Stop] => Hata: " . $e->getMessage());
                    $kernelLog['admin']['stop_error'] = "Admin stop bildirimi gönderiminde hata: " . $e->getMessage();
                }
            } else {
                $kernelLog['admin']['stop'] = "Admin stop bildirimi daha önce gönderilmiş.";
            }
        }
    }

    // ---------------------------------------------------------
    // (Diğer Yardımcı Fonksiyonlar - Çoğu Aynı, Ufak Değişiklikler Var)
    // ---------------------------------------------------------
    private function isWithinProximity($currentLocation, $designatedLocation, $threshold = 0.001)
    {
        // Haversine formülü veya daha gelişmiş bir yöntem KULLANILABİLİR (isteğe bağlı)
        // ... (şimdilik aynı bırakıyorum)
        list($currLat, $currLng) = explode(',', $currentLocation);
        list($desLat, $desLng) = explode(',', $designatedLocation);
        $latDiff = abs(floatval($currLat) - floatval($desLat));
        $lngDiff = abs(floatval($currLng) - floatval($desLng));
        return ($latDiff < $threshold && $lngDiff < $threshold);

    }

    private function isHolidayOrWeekend()
    {
        $today = Carbon::today($this->timezone); // Zaman dilimi
        if (in_array($today->dayOfWeek, [\Carbon\CarbonInterface::SATURDAY, \Carbon\CarbonInterface::SUNDAY])) {
            return true;
        }
        $holiday = DB::table('holidays')
            ->where('start_date', '<=', $today->toDateString())
            ->where('end_date', '>=', $today->toDateString())
            ->first();
        return $holiday ? true : false;
    }

    private function isUserOnLeave(User $user): bool
    {
        $today = Carbon::today($this->timezone);

        if ($today->isWeekend()) {
            return WeekendControlController::isWeekendActiveForUser($user) ? false : true;
        }

        $leave = DB::table('halfday_requests')
            ->where('user_id', $user->id)
            ->where('date', $today)
            ->whereIn('type', ['morning', 'rapor'])
            ->where('status', 'approved')
            ->first();

        return !empty($leave);
    }
    private function isUserInShift($user)
    {
        $today = Carbon::today($this->timezone)->toDateString(); // Zaman dilimi ve sadece tarih
        $shift = DB::table('shift_logs')
            ->where('user_id', $user->id)
            ->whereDate('shift_date', $today)
            ->first();
        return $shift ? true : false;
    }

    private function sendEarlyCheckInNotification($user)
    {
        try {
            $factory = (new \Kreait\Firebase\Factory)
                ->withServiceAccount(config('services.firebase.credentials_file'));
            $messaging = $factory->createMessaging();

            \Log::info("[sendEarlyCheckInNotification] => User ID: {$user->id} için erken giriş bildirimi gönderiliyor.");

            $tokens = DB::table('user_fcm_tokens')
                ->where('user_id', $user->id)
                ->pluck('fcm_token')
                ->toArray();
            if (empty($tokens)) {
                \Log::info("[sendEarlyCheckInNotification] => User ID: {$user->id} için token bulunamadı.");
                return;
            }

            $message = \Kreait\Firebase\Messaging\CloudMessage::new()
                ->withNotification([
                    'title' => 'Yaklaştınız',
                    'body' => 'Belirlenen giriş noktanıza yaklaştınız, lütfen giriş yapınız.',
                ])
                ->withData([
                    'action' => 'early_check_in',
                    'user_id' => (string) $user->id,
                ]);

            $sendReport = $messaging->sendMulticast($message, $tokens);
            $successCount = $sendReport->successes()->count();
            $failureCount = $sendReport->failures()->count();
            \Log::info("[sendEarlyCheckInNotification] => User ID: {$user->id} için push gönderimi: success=$successCount, failure=$failureCount");
        } catch (\Exception $e) {
            \Log::error("[sendEarlyCheckInNotification] => Hata: " . $e->getMessage());
        }
    }

    // ---------------------------------------------------------
// Module C: DB Tabanlı Resume Kontrolü ve Bildirimi (DÜZENLENMİŞ)

    private function checkWorkManagerStatus($user, WorkmanagerLog $wmLog, array &$kernelLog)
    {
        $now = Carbon::now(self::TIMEZONE);

        // 1. Geo Log Kontrolü: Bugüne ait en son geo log kaydını çekiyoruz.
        $lastGeoLog = DB::table('geo_logs')
            ->where('user_id', $user->id)
            ->whereBetween('created_at', [Carbon::now('UTC')->startOfDay(), Carbon::now('UTC')->endOfDay()])
            ->orderBy('created_at', 'desc')
            ->first();

        $geoDiff = null;
        if ($lastGeoLog) {
            $geoDiff = $now->diffInMinutes(Carbon::parse($lastGeoLog->created_at));
            $kernelLog[$user->id]['geoLog'] = "Son geo log {$geoDiff} dk önce.";
        } else {
            $kernelLog[$user->id]['geoLog'] = "Bugüne ait geo log kaydı bulunamadı.";
        }

        // 2. Workmanager Kapalı mı? (Eğer son kayıt 10 dk veya daha eskiyse)
        if (!$lastGeoLog || ($geoDiff !== null && $geoDiff >= self::NO_GEO_LOG_THRESHOLD_MINUTES)) {
            $kernelLog[$user->id]['workmanagerStatus'] = "Workmanager kapalı (son geo log: {$geoDiff} dk).";
        } else {
            $kernelLog[$user->id]['workmanagerStatus'] = "Workmanager aktif (son geo log: {$geoDiff} dk), resume bildirimi gönderilmiyor.";
            return; // Workmanager aktifse resume gönderimi yapılmaz.
        }

        // 3. wm_notification_logs kontrolü: Son resume bildirimi gönderim zamanını alıyoruz.
        $lastResumeLog = DB::table('wm_notification_logs')
            ->where('user_id', $user->id)
            ->where('command', 'resume')
            ->orderBy('created_at', 'desc')
            ->first();
        $lastResumeTime = $lastResumeLog ? Carbon::parse($lastResumeLog->created_at) : null;

        // Eğer son resume bildirimi 6 dk içinde gönderilmişse, tekrar göndermiyoruz.
        if ($lastResumeTime && $now->diffInMinutes($lastResumeTime) < 6) {
            $kernelLog[$user->id]['resumeStatus'] = "Resume zaten {$now->diffInMinutes($lastResumeTime)} dk önce gönderilmiş; yeni bildirim bekleniyor.";
            return;
        }

        // 4. Maksimum deneme ve 2 saat bekleme kontrolü
        $attemptCount = $wmLog->resume_attempt_count ?? 0;
        if ($attemptCount >= self::MAX_RESUME_ATTEMPTS) {
            if ($lastResumeTime && $now->diffInHours($lastResumeTime) < self::RESUME_WAIT_HOURS) {
                $kernelLog[$user->id]['resumeStatus'] = "Max {$attemptCount} deneme yapıldı, 2 saatlik bekleme süresi henüz dolmadı.";
                return;
            } else {
                $wmLog->resume_attempt_count = 0;
                $wmLog->resume_sent_time = null;
                $wmLog->save();
                $kernelLog[$user->id]['resumeReset'] = "2 saat bekleme süresi doldu, deneme sayısı sıfırlandı.";
            }
        }

        // 5. Resume bildirimi gönderiliyor.
        $kernelLog[$user->id]['resumeAction'] = "Workmanager kapalı, resume bildirimi gönderiliyor (Deneme: " . ($attemptCount + 1) . ").";
        try {
            $this->triggerWorkManagerResumeDB($user, $wmLog, $kernelLog);
        } catch (\Exception $e) {
            \Log::error("[checkWorkManagerStatus] Hata: " . $e->getMessage());
            $kernelLog[$user->id]['resumeError'] = "Hata: " . $e->getMessage();
        }
    }

    // ---------------------------------------------------------
    private function checkWorkManagerResumeStatusDB($user, WorkmanagerLog $wmLog, array &$kernelLog)
    {
        try {
            // 1) Şu anki zaman (hiçbir timezone kullanmıyoruz)
            $now = Carbon::now();

            // 2) Workmanager log üzerinden son resume bilgileri
            $attemptCount = $wmLog->resume_attempt_count ?? 0;
            $sentTime = $wmLog->resume_sent_time ? Carbon::parse($wmLog->resume_sent_time) : null;

            // 3) Bugüne ait en son geo log kaydını çekiyoruz
            $todayDate = Carbon::today()->toDateString();
            \Log::info("User {$user->id}: Checking geo_logs for date = {$todayDate}");

            $lastGeoLog = DB::table('geo_logs')
                ->where('user_id', $user->id)
                ->whereDate('created_at', $todayDate)
                ->orderBy('created_at', 'desc')
                ->first();

            $geoDiff = null;
            if ($lastGeoLog) {
                // created_at ile şu an arasındaki dakika farkı
                $geoDiff = Carbon::parse($lastGeoLog->created_at)->diffInMinutes($now);
                \Log::info("User {$user->id}: lastGeoLog created_at={$lastGeoLog->created_at}, geoDiff={$geoDiff} dk, now={$now}");
            } else {
                \Log::info("User {$user->id}: Bugüne ait geo_log bulunamadı.");
            }

            // 4) Workmanager Kapalı mı?
            //    - Hiç kayıt yoksa ya da son kayıt >= 10 dk önceyse
            if (!$lastGeoLog || ($geoDiff !== null && $geoDiff >= self::NO_GEO_LOG_THRESHOLD_MINUTES)) {
                \Log::info("User {$user->id}: Workmanager kapalı kabul edildi. (geoDiff={$geoDiff})");
                $kernelLog[$user->id]['status'] = "Workmanager kapalı (≥ " . self::NO_GEO_LOG_THRESHOLD_MINUTES . " dk).";
            } else {
                \Log::info("User {$user->id}: Workmanager aktif, resume tetiklenmiyor. (geoDiff={$geoDiff})");
                $kernelLog[$user->id]['status'] = "Workmanager aktif (geoDiff={$geoDiff}).";
                return; // Aktif olduğu için resume gönderilmez
            }

            // 5) Resume gönderimi öncesi kontroller

            // 5a) Son gönderimden 6 dk geçmemişse
            if ($sentTime) {
                $diffSinceLastResume = $now->diffInMinutes($sentTime);
                if ($diffSinceLastResume < 6) {
                    \Log::info("User {$user->id}: Resume bildirimi {$diffSinceLastResume} dk önce gönderilmiş, 6 dk dolmadan gönderilmiyor.");
                    $kernelLog[$user->id]['resume'] = "Son resume 6 dk dolmadı, iptal.";
                    return;
                }
            }

            // 5b) Maksimum deneme sayısı (3) ve 2 saat bekleme
            if ($attemptCount >= self::MAX_RESUME_ATTEMPTS) {
                if ($sentTime && $now->diffInHours($sentTime) < self::RESUME_WAIT_HOURS) {
                    \Log::info("User {$user->id}: Max deneme ({$attemptCount}) aşıldı, 2 saat bekleme süresi dolmadı.");
                    $kernelLog[$user->id]['resume'] = "Max deneme, 2 saat dolmadı.";
                    return;
                } else {
                    // Bekleme süresi doldu, resetliyoruz
                    $wmLog->resume_attempt_count = 0;
                    $wmLog->resume_sent_time = null;
                    $wmLog->save();
                    \Log::info("User {$user->id}: 2 saat bekleme doldu, resume deneme sayısı sıfırlandı.");
                    $kernelLog[$user->id]['resumeReset'] = "2 saat bekleme doldu, reset.";
                }
            }

            // 6) Buraya geldiysek resume bildirimi tetiklenebilir
            \Log::info("User {$user->id}: Resume bildirimi gönderilecek (Deneme: " . ($attemptCount + 1) . ")");
            $kernelLog[$user->id]['resume_action'] = "Resume tetikleniyor, attempt=" . ($attemptCount + 1);

            $this->triggerWorkManagerResumeDB($user, $wmLog, $kernelLog);
        } catch (\Exception $e) {
            \Log::error("User {$user->id}: checkWorkManagerResumeStatusDB Hata => " . $e->getMessage());
            $kernelLog[$user->id]['resume_error'] = "Hata => " . $e->getMessage();
        }
    }



    private function triggerWorkManagerResumeDB($user, WorkmanagerLog $wmLog, array &$kernelLog)
    {

        \Log::info("[triggerWorkManagerResumeDB] => User ID: {$user->id} için resume bildirimi gönderiliyor.");

        try {
            $now = Carbon::now($this->timezone);  // Zaman dilimi!
            $wmLog->resume_sent_time = $now; // Carbon nesnesi ve timezone
            $wmLog->resume_attempt_count = ($wmLog->resume_attempt_count ?? 0) + 1;
            $wmLog->save();

            $factory = (new \Kreait\Firebase\Factory)
                ->withServiceAccount(config('services.firebase.credentials_file'));
            $messaging = $factory->createMessaging();
            $tokens = DB::table('user_fcm_tokens')
                ->where('user_id', $user->id)
                ->pluck('fcm_token')
                ->toArray();

            if (empty($tokens)) {
                \Log::info("[triggerWorkManagerResumeDB] => User ID: {$user->id} için FCM token bulunamadı.");
                $kernelLog[$user->id]['resume'] = "FCM token bulunamadı.";
                return;
            }

            $msg = \Kreait\Firebase\Messaging\CloudMessage::new()
                ->withNotification([
                    'title' => 'WorkManager Komut',
                    'body' => 'WorkManager Resume: Lütfen durumunuzu kontrol ediniz.',
                ])
                ->withData([
                    'action' => 'resume',
                    'user_id' => (string) $user->id,
                ]);

            $sendReport = $messaging->sendMulticast($msg, $tokens);
            $successCount = $sendReport->successes()->count();
            $failureCount = $sendReport->failures()->count();

            \Log::info("[triggerWorkManagerResumeDB] => Resume push gönderildi. Success={$successCount}, Failure={$failureCount}");
            $kernelLog[$user->id]['resume'] = "Resume push gönderildi: Success={$successCount}, Failure={$failureCount}.";

            // LOG KAYDI (try-catch içinde!)
            try {
                DB::table('wm_notification_logs')->insert([
                    'user_id' => $user->id,
                    'command' => 'resume',
                    'status' => 'sent',
                    'explanation' => 'Resume push gönderildi: GEO_LOGS kaydı mevcut değil veya ≥ ' . self::NO_GEO_LOG_THRESHOLD_MINUTES . ' dk, deneme: ' . $wmLog->resume_attempt_count,
                    'created_at' => $now->toDateTimeString(), // Carbon Nesnesi ve zaman dilimi!
                    'updated_at' => $now->toDateTimeString(), // Carbon Nesnesi ve zaman dilimi!
                ]);
            } catch (\Exception $e) {
                \Log::error("[triggerWorkManagerResumeDB: Log Kaydı] => Hata: " . $e->getMessage());
                $kernelLog[$user->id]['resume_log_error'] = "Resume log kaydı hatası: " . $e->getMessage();
            }

        } catch (\Exception $e) {
            \Log::error("[triggerWorkManagerResumeDB] => Hata: " . $e->getMessage());
            $kernelLog[$user->id]['resume_error'] = "Resume bildirimi gönderiminde hata: " . $e->getMessage();
        }
    }

    // ---------------------------------------------------------
    // Module C: Stop Kontrol ve Bildirim İşlemleri (DÜZENLENMİŞ)
    // ---------------------------------------------------------
    private function checkWorkManagerStopStatus($user)
    {
        // Beklenen kontrol zamanı (timezone ile)
        $expectedCheckTime = Carbon::createFromTimeString(self::STOP_CHECK_TIME, $this->timezone);
        $now = Carbon::now($this->timezone);

        // Şu an beklenen zamandan önceyse, kontrolü atla.
        // ---------------------------------------------------------
        // Module C: Stop Kontrol ve Bildirim İşlemleri (DÜZENLENMİŞ) - DEVAM
        // ---------------------------------------------------------   
        // Beklenen kontrol zamanı (timezone ile)
        $expectedCheckTime = Carbon::createFromTimeString(self::STOP_CHECK_TIME, $this->timezone);
        $now = Carbon::now($this->timezone);

        // Şu an beklenen zamandan önceyse, kontrolü atla.
        if ($now->lessThan($expectedCheckTime)) {
            \Log::info("[checkWorkManagerStopStatus] => User ID: {$user->id} için kontrol zamanı henüz gelmedi.");
            return;
        }

        // Son geo log'u al (bugüne ait)
        $lastGeoLog = DB::table('geo_logs')
            ->where('user_id', $user->id)
            ->whereDate('created_at', Carbon::today($this->timezone)) // Bugüne ait
            ->orderBy('created_at', 'desc')
            ->first();

        // Geo log varsa ve beklenen zamandan ÖNCE ise, stop başarılı.
        if ($lastGeoLog) {
            $lastLogTime = Carbon::parse($lastGeoLog->created_at, $this->timezone);
            if ($lastLogTime->lessThan($expectedCheckTime)) {
                \Log::info("[checkWorkManagerStopStatus] => User ID: {$user->id} için WorkManager stop başarılı. (Son güncelleme: {$lastLogTime->toDateTimeString()})");
            } else {
                // Geo log var ama beklenen zamandan SONRA.  Yeniden dene.
                \Log::warning("[checkWorkManagerStopStatus] => User ID: {$user->id} için WorkManager stop başarısız, yeniden denenecek. (Son güncelleme: {$lastLogTime->toDateTimeString()})");
                $this->triggerWorkManagerStop($user); // try-catch içinde çağır.
            }
        } else {
            // Geo log yoksa, stop KONTROLÜ yapılamadı (log'a yaz).
            \Log::warning("[checkWorkManagerStopStatus] => User ID: {$user->id} için geo_log bulunamadı, stop kontrolü yapılamadı.");
            // Burada da bildirim gönderilebilir (isteğe bağlı), ama emin değiliz.
        }
    }


    private function triggerWorkManagerStop($user)
    {
        \Log::info("[triggerWorkManagerStop] => User ID: {$user->id} için yeniden stop bildirimi gönderiliyor.");

        try { // TÜM İŞLEMİ TRY-CATCH İÇİNE AL
            // Yeni günün başlangıcı ve bitişi (timezone ile!)
            $startOfDay = Carbon::today($this->timezone)->startOfDay()->toDateTimeString();
            $endOfDay = Carbon::today($this->timezone)->endOfDay()->toDateTimeString();

            // Aynı gün içinde daha önce stop bildirimi gönderilmiş mi?
            $existing = DB::table('wm_notification_logs')
                ->where('user_id', $user->id)
                ->where('command', 'stop')
                ->whereBetween('created_at', [$startOfDay, $endOfDay])
                ->first();

            if ($existing) {
                \Log::info("[triggerWorkManagerStop] => User ID: {$user->id} için stop bildirimi daha önce gönderilmiş.");
                return; // Fonksiyondan çık
            }

            // Bildirim gönder (FCMHelper kullan)
            FCMHelper::sendNotification($user, 'WorkManager Stop: Lütfen durumu kontrol ediniz.');

            // LOG KAYDI (try-catch içinde!)
            try {
                DB::table('wm_notification_logs')->insert([
                    'user_id' => $user->id,
                    'command' => 'stop',
                    'status' => 'sent',
                    'explanation' => 'Stop bildirimi gönderildi: Kullanıcı check-out sonrası 2 dk bekleyip durdu.',
                    'created_at' => Carbon::now($this->timezone)->toDateTimeString(), // Carbon ve timezone!
                    'updated_at' => Carbon::now($this->timezone)->toDateTimeString(), // Carbon ve timezone!
                ]);
                \Log::info("[triggerWorkManagerStop] => User ID: {$user->id} için stop bildirimi gönderildi ve kayıt oluşturuldu.");
            } catch (\Exception $e) {
                \Log::error("[triggerWorkManagerStop: Log Kaydı] => Hata: " . $e->getMessage());
            }


        } catch (\Exception $e) {
            \Log::error("[triggerWorkManagerStop] => Hata: " . $e->getMessage());
        }
    }

    // ---------------------------------------------------------
    // (Opsiyonel) Özel Çalışma Saatleri Kontrolü
    // ---------------------------------------------------------
    private function hasCustomHours($user)
    {
        return false; // Şimdilik false döndürüyoruz.
    }

    // ---------------------------------------------------------
    // (Opsiyonel) Resume Kontrol Özet Bildirimi
    // ---------------------------------------------------------
    private function sendResumeSummaryNotification(array $kernelLog)
    {
        \Log::info("[sendResumeSummaryNotification] => Resume özet bildirimi gönderiliyor.");
        // Özet bildirimi gönderimi için gerekli işlemleri burada yap.
        // (Şimdilik boş bırakıyorum)
    }

    // ---------------------------------------------------------
    // (Opsiyonel) Workmanager'ın aktif olup olmadığını kontrol eder.
    // ---------------------------------------------------------
    private function isWorkManagerActive(WorkmanagerLog $wmLog)
    {
        $now = Carbon::now($this->timezone); // Zaman dilimi
        $lastGeoLog = DB::table('geo_logs')
            ->where('user_id', $wmLog->user_id)
            ->orderBy('created_at', 'desc')
            ->first();
        if ($lastGeoLog) {
            $diffSeconds = Carbon::parse($lastGeoLog->created_at, $this->timezone)->diffInSeconds($now);  // Zaman dilimi
            return $diffSeconds <= self::WORKMANAGER_ACTIVE_THRESHOLD_SECONDS;
        }
        return false;
    }
} // class UpdateWorkmanagerLogsCommand'ın kapanış parantezi
