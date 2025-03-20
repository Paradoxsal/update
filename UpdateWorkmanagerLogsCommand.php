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
    // Sabit Tanımlamalar (Magic Numbers ve Zaman Aralıkları)
    // ---------------------------------------------------------
    const ACTIVE_THRESHOLD_SECONDS = 40;               // Kullanıcının aktif sayılması için geo log zaman farkı (saniye)
    const RESUME_CACHE_SECONDS = 360;                    // Resume push bildiriminin tekrar gönderilmemesi süresi: 6 dakika (360 saniye)
    const EARLY_CHECKIN_START = '06:00:00';
    const EARLY_CHECKIN_END = '08:00:00';
    const MORNING_CHECKIN_START = '08:00:00';
    const MORNING_CHECKIN_END = '09:00:00';
    const STOP_CHECK_TIME = '18:05:00';                  // Stop kontrolü için beklenen zaman
    const WORKMANAGER_ACTIVE_THRESHOLD_SECONDS = 60;     // Workmanager'ın aktif kabulü için eşik (saniye)

    // Yeni sabitler
    const MAX_RESUME_ATTEMPTS = 3;                       // Resume bildirimi için maksimum deneme sayısı
    const NO_GEO_LOG_THRESHOLD_MINUTES = 20;             // GEO_LOGS kaydı yoksa veya 20 dk üzeriyse workmanager kapalı kabul
    const RESUME_WAIT_HOURS = 2;                         // Maksimum denemeden sonra bekleme süresi (saat)
    const WORKMANAGER_CONTROL_TOLERANCE_MINUTES = 3;     // 20 dk kontrolünde tolerans (dakika)
    // Zaman dilimi ayarı
    const TIMEZONE = 'Europe/Istanbul';
    // ---------------------------------------------------------
    // Komut Ayarları
    // ---------------------------------------------------------
    protected $signature = 'workmanager:updatelogs {--mode= : İşlem modu (varsayılan: normal, D: resume kontrol özet bildirimi)}';
    protected $description = 'Workmanager AI job: Kullanıcıların konum, izin/rapor durumlarına ve mesai bilgilerine göre workmanager_logs tablosunu günceller.';
    // ---------------------------------------------------------
    // handle() - Komutun Ana Giriş Noktası
    // ---------------------------------------------------------
    public function handle()
    {
        $now = Carbon::now(self::TIMEZONE);
        $today = Carbon::today(self::TIMEZONE)->format('Y-m-d');
        $kernelLog = [];

        $this->info('Workmanager log update job başlatıldı: ' . $now->toDateTimeString());

        // workmanager_ai değeri 1 olan kullanıcıları çek.
        $users = User::where('workmanager_ai', 1)->get();
        if ($users->isEmpty()) {
            $this->info('İşlenecek kullanıcı bulunamadı.');
            return;
        }

        // Her kullanıcı için işlemleri gerçekleştir.
        foreach ($users as $user) {
            $this->processUser($user, $now, $today, $kernelLog);
        }

        // Kernel logları JSON formatında dosyaya kaydetme
        $jsonData = json_encode($kernelLog, JSON_PRETTY_PRINT);
        $filename = 'workmanager_kernel_' . $today . '.json';
        Storage::disk('local')->put($filename, $jsonData);

        // Opsiyonel D modu: Resume kontrol özet bildirimi gönderilir.
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

        // 3) Mevcut workmanager_logs kaydı: Bugüne ait log kaydı çekilir.
        $wmLog = WorkmanagerLog::where('user_id', $user->id)
            ->whereDate('date', $today)
            ->first();
        if (!$wmLog) {
            $kernelLog[$user->id]['wmLog'] = 'Log kaydı bulunamadı, atlandı';
            return;
        }

        // 4) Geo Log Kontrolü: Bugüne ait en son geo log alınır.
        $lastGeoLog = DB::table('geo_logs')
            ->where('user_id', $user->id)
            ->whereDate('created_at', Carbon::today(self::TIMEZONE))
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$lastGeoLog) {
            // GEO_LOGS kaydı yoksa, resume mekanizması devreye girsin.
            $kernelLog[$user->id]['geoLog'] = 'Bugüne ait geo log bulunamadı; Resume tetikleniyor';
            $this->checkWorkManagerResumeStatusDB($user, $wmLog, $kernelLog);
            return;
        }

        // Geo log mevcutsa, konum bilgisi belirlenir.
        $currentLocation = isset($lastGeoLog->location)
            ? $lastGeoLog->location
            : ($lastGeoLog->lat . ',' . $lastGeoLog->lng);

        // 5) Kullanıcının aktifliği: Son geo log ile şimdiki zaman arasındaki fark hesaplanır.
        $diffSeconds = Carbon::parse($lastGeoLog->created_at)->diffInSeconds($now);
        $isActive = ($diffSeconds <= self::ACTIVE_THRESHOLD_SECONDS);
        $kernelLog[$user->id]['active'] = $isActive ? 'active' : 'inactive';

        // Eğer kullanıcı aktif değilse, resume kontrolü tetiklenir.
        if (!$isActive) {
            $kernelLog[$user->id]['action'] = 'User inactive; resume command triggered';
            $this->checkWorkManagerResumeStatusDB($user, $wmLog, $kernelLog);
            return;
        }

        // 6) Bugünkü attendance (giriş) kaydı alınır.
        $attendance = Attendance::where('user_id', $user->id)
            ->whereDate('check_in_time', $today)
            ->first();

        // 7) Sabah İşlemleri: Erken check-in ve giriş kontrolleri.
        $this->processMorningNotifications($user, $now, $wmLog, $attendance, $currentLocation, $kernelLog);

        // 8) Akşam İşlemleri: Check-out ve stop bildirimleri.
        $this->processEveningNotifications($user, $now, $wmLog, $attendance, $currentLocation, $kernelLog);
    }

    // ---------------------------------------------------------
    // Module B: Sabah İşlemleri - Giriş Kontrolleri ve Bildirimler
    // ---------------------------------------------------------
    private function processMorningNotifications($user, Carbon $now, $wmLog, $attendance, $currentLocation, array &$kernelLog)
    {
        if ($now->between(Carbon::createFromTimeString(self::EARLY_CHECKIN_START), Carbon::createFromTimeString(self::EARLY_CHECKIN_END))) {
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

        if (!$attendance && $now->between(Carbon::createFromTimeString(self::MORNING_CHECKIN_START), Carbon::createFromTimeString(self::MORNING_CHECKIN_END))) {
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
                if ($now->between(Carbon::createFromTime(12, 20, 0), Carbon::createFromTime(12, 21, 0)) && $wmLog->checkGiris12_20 == 0) {
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
    // Module B: Akşam İşlemleri - Check-out ve Stop Bildirimleri
    // ---------------------------------------------------------
    private function processEveningNotifications($user, Carbon $now, $wmLog, $attendance, $currentLocation, array &$kernelLog)
    {
        // Eğer kullanıcı vardiyadaysa akşam bildirimleri devre dışı kalsın.
        if ($this->isUserInShift($user)) {
            $kernelLog[$user->id]['evening'] = 'User in shift; evening notifications deferred';
            return;
        }

        // Kullanıcı check-in yapmış fakat check-out yapmamışsa
        if ($attendance && !$attendance->check_out_time) {
            // Zaman kontrolleri Europe/Istanbul zaman diliminde yapılıyor.
            $timeNow = Carbon::now(self::TIMEZONE);

            // 16:50 kontrolü: Kullanıcı konumdaysa
            if ($timeNow->greaterThanOrEqualTo(Carbon::createFromTime(16, 50, 0, self::TIMEZONE)) && $wmLog->checkCikis1655 == 0) {
                if ($this->isWithinProximity($currentLocation, $user->check_out_location)) {
                    $wmLog->checkCikis1655 = 1;
                    $wmLog->save();
                    $kernelLog[$user->id]['checkCikis1655'] = 'Updated at 16:50';
                }
            }
            // 17:08 kontrolü: Kullanıcı hâlâ çıkış yapmamışsa ve konumdaysa
            if ($timeNow->greaterThanOrEqualTo(Carbon::createFromTime(17, 8, 0, self::TIMEZONE)) && $wmLog->checkCicis1715 == 0) {
                if ($this->isWithinProximity($currentLocation, $user->check_out_location)) {
                    $wmLog->checkCicis1715 = 1;
                    $wmLog->save();
                    $kernelLog[$user->id]['checkCicis1715'] = 'Updated at 17:08';
                }
            }
            // 17:40 kontrolü: Konum kontrolü olmaksızın
            if ($timeNow->greaterThanOrEqualTo(Carbon::createFromTime(17, 40, 0, self::TIMEZONE)) && $wmLog->checkCicisAfter1740 == 0) {
                $wmLog->checkCicisAfter1740 = 1;
                $wmLog->save();
                $kernelLog[$user->id]['checkCicisAfter1740'] = 'Updated at 17:40';
            }
        } else if ($attendance && $attendance->check_out_time) {
            $checkOutTime = Carbon::parse($attendance->check_out_time);
            if ($now->diffInMinutes($checkOutTime) >= 2) {
                $kernelLog[$user->id]['stop'] = 'User stopped 2 minutes after check-out';
                $this->checkWorkManagerStopStatus($user);
            } else {
                $kernelLog[$user->id]['evening'] = 'User recently checked out, waiting for stop trigger';
            }
        } else {
            $kernelLog[$user->id]['evening'] = 'User did not check in; evening processing skipped';
        }
    }

    // ---------------------------------------------------------
    // Module A: Admin Bildirimleri (Resume & Stop) - Tekrarlı Bildirimleri Engelleyen Yapı
    // ---------------------------------------------------------
    private function sendAdminNotifications(Carbon $now, array &$kernelLog)
    {
        $admin = User::where('role', 1)->first();
        if (!$admin) {
            return;
        }

        // Europe/Istanbul zaman dilimi
        $timezone = self::TIMEZONE;
        $timeNow = Carbon::now($timezone);
        $today = Carbon::now($timezone)->toDateString();

        // ------------------------------
        // Admin Resume Bildirimi (örneğin saat 08:00'de)
        // ------------------------------
        if ($timeNow->hour == 8) {
            $startOfDay = Carbon::createFromFormat('Y-m-d H:i:s', $today . ' 00:00:00', $timezone);
            $endOfDay   = Carbon::createFromFormat('Y-m-d H:i:s', $today . ' 23:59:59', $timezone);
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
                    DB::table('wm_notification_logs')->insert([
                        'user_id'     => $admin->id,
                        'command'     => 'admin_resume',
                        'status'      => 'sent',
                        'explanation' => 'Admin Bildirimi: Aktif mesai sayısı: ' . $mesaiCount,
                        'created_at'  => Carbon::now($timezone)->toDateTimeString(),
                        'updated_at'  => Carbon::now($timezone)->toDateTimeString(),
                    ]);
                    $kernelLog['admin']['resume'] = "Admin resume bildirimi gönderildi: $message";
                } catch (\Exception $e) {
                    \Log::error("[sendAdminNotifications] => Admin resume bildirimi hatası: " . $e->getMessage());
                    $kernelLog['admin']['resume_error'] = "Admin resume bildirimi gönderiminde hata: " . $e->getMessage();
                }
            } else {
                $kernelLog['admin']['resume'] = "Admin resume bildirimi daha önce gönderilmiş.";
            }
        }

        // ------------------------------
        // Admin Stop Bildirimi: Saat 00:00'daki bildirim için, kayıt sonraki günün tarihine göre oluşturulacak.
        // ------------------------------
        if ($timeNow->hour == 0) {
            // Bildirimin kayıt tarihi olarak sonraki günü kullanıyoruz.
            $notificationDate = Carbon::tomorrow($timezone);
            $startOfDay = Carbon::createFromFormat('Y-m-d H:i:s', $notificationDate->format('Y-m-d') . ' 00:00:00', $timezone);
            $endOfDay   = Carbon::createFromFormat('Y-m-d H:i:s', $notificationDate->format('Y-m-d') . ' 23:59:59', $timezone);

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
                    DB::table('wm_notification_logs')->insert([
                        'user_id'     => $admin->id,
                        'command'     => 'admin_stop',
                        'status'      => 'sent',
                        'explanation' => 'Admin Bildirimi: Mesai kalan kullanıcı: ' . $stopCount . ' bildirimi gönderildi.',
                        'created_at'  => $notificationDate->toDateTimeString(),
                        'updated_at'  => $notificationDate->toDateTimeString(),
                    ]);
                    $kernelLog['admin']['stop'] = "Admin stop bildirimi gönderildi: $message";
                } catch (\Exception $e) {
                    \Log::error("[sendAdminNotifications] => Admin stop bildirimi hatası: " . $e->getMessage());
                    $kernelLog['admin']['stop_error'] = "Admin stop bildirimi gönderiminde hata: " . $e->getMessage();
                }
            } else {
                $kernelLog['admin']['stop'] = "Admin stop bildirimi daha önce gönderilmiş.";
            }
        }
    }

    // ---------------------------------------------------------
    // Module B: Konum Yakınlık Kontrolü
    // ---------------------------------------------------------
    private function isWithinProximity($currentLocation, $designatedLocation, $threshold = 0.001)
    {
        list($currLat, $currLng) = explode(',', $currentLocation);
        list($desLat, $desLng) = explode(',', $designatedLocation);
        $latDiff = abs(floatval($currLat) - floatval($desLat));
        $lngDiff = abs(floatval($currLng) - floatval($desLng));
        return ($latDiff < $threshold && $lngDiff < $threshold);
    }

    // ---------------------------------------------------------
    // Module A: Hafta Sonu/Tatil Kontrolleri ve İzin Durumu
    // ---------------------------------------------------------
    private function isHolidayOrWeekend()
    {
        $today = Carbon::today(self::TIMEZONE);
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
        $today = Carbon::today(self::TIMEZONE);
        if ($today->isWeekend()) {
            return WeekendControlController::isWeekendActiveForUser($user) ? false : true;
        }
        $leave = DB::table('halfday_requests')
            ->where('user_id', $user->id)
            ->where('date', $today->toDateString())
            ->whereIn('type', ['morning', 'rapor'])
            ->where('status', 'approved')
            ->first();
        return $leave ? true : false;
    }

    private function isUserInShift($user)
    {
        $today = Carbon::today(self::TIMEZONE)->toDateString();
        $shift = DB::table('shift_logs')
            ->where('user_id', $user->id)
            ->whereDate('shift_date', $today)
            ->first();
        return $shift ? true : false;
    }

    // ---------------------------------------------------------
    // Module C: Bildirim Gönderim Fonksiyonları (Firebase)
    // ---------------------------------------------------------
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
    // Module C: DB Tabanlı Resume Kontrolü ve Bildirimi
    // ---------------------------------------------------------
    private function checkWorkManagerResumeStatusDB($user, WorkmanagerLog $wmLog, array &$kernelLog)
    {
        $now = Carbon::now(self::TIMEZONE);
        $attemptCount = $wmLog->resume_attempt_count ?? 0;
        $sentTime = $wmLog->resume_sent_time ? Carbon::parse($wmLog->resume_sent_time) : null;

        // GEO_LOGS kontrolü: Bugüne ait en son kaydın zamanı alınır.
        $lastGeoLog = DB::table('geo_logs')
            ->where('user_id', $user->id)
            ->whereDate('created_at', Carbon::today(self::TIMEZONE))
            ->orderBy('created_at', 'desc')
            ->first();

        $workmanagerOff = false;
        $geoDiff = null;
        if ($lastGeoLog) {
            $geoDiff = $now->diffInMinutes(Carbon::parse($lastGeoLog->created_at));
            if ($geoDiff >= self::NO_GEO_LOG_THRESHOLD_MINUTES) {
                $workmanagerOff = true;
                $kernelLog[$user->id]['geoLog'] = "Son geo log {$geoDiff} dk önce; (≥ " . self::NO_GEO_LOG_THRESHOLD_MINUTES . " dk) workmanager kapalı kabul edildi.";
            } else {
                $kernelLog[$user->id]['geoLog'] = "Workmanager aktif: Son geo log {$geoDiff} dk önce.";
            }
        } else {
            $workmanagerOff = true;
            $kernelLog[$user->id]['geoLog'] = "Bugüne ait geo log kaydı bulunamadı; workmanager kapalı kabul edildi.";
        }

        if (!$workmanagerOff) {
            $kernelLog[$user->id]['resume'] = "Workmanager aktif; resume bildirimi gönderilmiyor.";
            return;
        }

        if ($sentTime) {
            $diffMinutes = $now->diffInMinutes($sentTime);
            if ($diffMinutes < (self::RESUME_CACHE_SECONDS / 60)) {
                $kernelLog[$user->id]['resume'] = "Resume bildirimi {$diffMinutes} dk önce gönderilmiş; 6 dk aralık geçmeden yeni push gönderilmiyor.";
                return;
            }
        }

        if ($attemptCount >= self::MAX_RESUME_ATTEMPTS) {
            if ($sentTime && $now->diffInHours($sentTime) < self::RESUME_WAIT_HOURS) {
                $kernelLog[$user->id]['resume'] = "Max {$attemptCount} deneme yapıldı, ancak 2 saatlik bekleme süresi henüz dolmadı.";
                return;
            } else {
                $wmLog->resume_attempt_count = 0;
                $wmLog->resume_sent_time = null;
                $wmLog->save();
                $kernelLog[$user->id]['resumeReset'] = "2 saat bekleme süresi doldu; deneme sayısı sıfırlandı.";
            }
        }

        $kernelLog[$user->id]['resume_action'] = "Workmanager kapalı; resume bildirimi tetikleniyor. (Deneme: " . ($attemptCount + 1) . ")";
        try {
            $this->triggerWorkManagerResumeDB($user, $wmLog, $kernelLog);
        } catch (\Exception $e) {
            \Log::error("[checkWorkManagerResumeStatusDB] => Hata: " . $e->getMessage());
            $kernelLog[$user->id]['resume_error'] = "Hata: " . $e->getMessage();
        }
    }

    private function triggerWorkManagerResumeDB($user, WorkmanagerLog $wmLog, array &$kernelLog)
    {
        \Log::info("[triggerWorkManagerResumeDB] => User ID: {$user->id} için resume bildirimi gönderiliyor.");
        try {
            $now = Carbon::now(self::TIMEZONE);
            $wmLog->resume_sent_time = $now;
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

            DB::table('wm_notification_logs')->insert([
                'user_id'     => $user->id,
                'command'     => 'resume',
                'status'      => 'sent',
                'explanation' => 'Resume push gönderildi: GEO_LOGS kaydı mevcut değil veya ≥ ' . self::NO_GEO_LOG_THRESHOLD_MINUTES . ' dk, deneme: ' . $wmLog->resume_attempt_count,
                'created_at'  => $now->toDateTimeString(),
                'updated_at'  => $now->toDateTimeString(),
            ]);
        } catch (\Exception $e) {
            \Log::error("[triggerWorkManagerResumeDB] => Hata: " . $e->getMessage());
            $kernelLog[$user->id]['resume_error'] = "Resume bildirimi gönderiminde hata: " . $e->getMessage();
        }
    }

    // ---------------------------------------------------------
    // Module C: Stop Kontrol ve Bildirim İşlemleri
    // ---------------------------------------------------------
    private function checkWorkManagerStopStatus($user)
    {
        $expectedCheckTime = Carbon::createFromTimeString(self::STOP_CHECK_TIME, self::TIMEZONE);
        $now = Carbon::now(self::TIMEZONE);
        if ($now->lessThan($expectedCheckTime)) {
            \Log::info("[checkWorkManagerStopStatus] => User ID: {$user->id} için kontrol zamanı henüz gelmedi.");
            return;
        }
        $lastGeoLog = DB::table('geo_logs')
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->first();
        if ($lastGeoLog) {
            $lastLogTime = Carbon::parse($lastGeoLog->created_at);
            if ($lastLogTime->lessThan($expectedCheckTime)) {
                \Log::info("[checkWorkManagerStopStatus] => User ID: {$user->id} için WorkManager stop başarılı. (Son güncelleme: {$lastLogTime->toDateTimeString()})");
            } else {
                \Log::warning("[checkWorkManagerStopStatus] => User ID: {$user->id} için WorkManager stop başarısız, yeniden denenecek. (Son güncelleme: {$lastLogTime->toDateTimeString()})");
                $this->triggerWorkManagerStop($user);
            }
        } else {
            \Log::warning("[checkWorkManagerStopStatus] => User ID: {$user->id} için geo_log bulunamadı, stop kontrolü yapılamadı.");
        }
    }

    private function triggerWorkManagerStop($user)
    {
        \Log::info("[triggerWorkManagerStop] => User ID: {$user->id} için yeniden stop bildirimi gönderiliyor.");
        try {
            // Yeni günün başlangıcı ve bitişi (Europe/Istanbul)
            $startOfDay = Carbon::today(self::TIMEZONE)->startOfDay()->toDateTimeString();
            $endOfDay = Carbon::today(self::TIMEZONE)->endOfDay()->toDateTimeString();
            $existing = DB::table('wm_notification_logs')
                ->where('user_id', $user->id)
                ->where('command', 'stop')
                ->whereBetween('created_at', [$startOfDay, $endOfDay])
                ->first();
            if ($existing) {
                \Log::info("[triggerWorkManagerStop] => User ID: {$user->id} için stop bildirimi daha önce gönderilmiş.");
                return;
            }

            FCMHelper::sendNotification($user, 'WorkManager Stop: Lütfen durumu kontrol ediniz.');
            DB::table('wm_notification_logs')->insert([
                'user_id'     => $user->id,
                'command'     => 'stop',
                'status'      => 'sent',
                'explanation' => 'Stop bildirimi gönderildi: Kullanıcı check-out sonrası 2 dk bekleyip durdu.',
                'created_at'  => Carbon::now(self::TIMEZONE)->toDateTimeString(),
                'updated_at'  => Carbon::now(self::TIMEZONE)->toDateTimeString(),
            ]);
            \Log::info("[triggerWorkManagerStop] => User ID: {$user->id} için stop bildirimi gönderildi ve kayıt oluşturuldu.");
        } catch (\Exception $e) {
            \Log::error("[triggerWorkManagerStop] => Hata: " . $e->getMessage());
        }
    }

    // ---------------------------------------------------------
    // (Opsiyonel) Özel Çalışma Saatleri Kontrolü
    // ---------------------------------------------------------
    private function hasCustomHours($user)
    {
        return false;
    }

    // ---------------------------------------------------------
    // (Opsiyonel) Resume Kontrol Özet Bildirimi
    // ---------------------------------------------------------
    private function sendResumeSummaryNotification(array $kernelLog)
    {
        \Log::info("[sendResumeSummaryNotification] => Resume özet bildirimi gönderiliyor.");
        // Özet bildirimi gönderimi için gerekli işlemleri burada gerçekleştirin.
    }

    // ---------------------------------------------------------
    // (Opsiyonel) Workmanager'ın aktif olup olmadığını kontrol eder.
    // ---------------------------------------------------------
    private function isWorkManagerActive(WorkmanagerLog $wmLog)
    {
        $now = Carbon::now(self::TIMEZONE);
        $lastGeoLog = DB::table('geo_logs')
            ->where('user_id', $wmLog->user_id)
            ->orderBy('created_at', 'desc')
            ->first();
        if ($lastGeoLog) {
            $diffSeconds = Carbon::parse($lastGeoLog->created_at)->diffInSeconds($now);
            return $diffSeconds <= self::WORKMANAGER_ACTIVE_THRESHOLD_SECONDS;
        }
        return false;
    }
}
