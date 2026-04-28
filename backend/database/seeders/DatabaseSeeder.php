<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ── Школа ────────────────────────────────────────────────
        $schoolId = Str::uuid();
        DB::table('schools')->insert([
            'id'         => $schoolId,
            'name'       => 'Школа №1 г. Бишкек',
            'slug'       => 'school-1-bishkek',
            'address'    => 'ул. Московская 123, Бишкек',
            'timezone'   => 'Asia/Bishkek',
            'settings'   => json_encode(['language' => 'ru']),
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // ── Пользователи ─────────────────────────────────────────
        $adminId = Str::uuid();
        $supervisorId = Str::uuid();
        $teacherId = Str::uuid();

        DB::table('users')->insert([
            [
                'id'         => $adminId,
                'school_id'  => $schoolId,
                'name'       => 'Администратор',
                'email'      => 'admin@school.kg',
                'password'   => Hash::make('password'),
                'role'       => 'admin',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id'         => $supervisorId,
                'school_id'  => $schoolId,
                'name'       => 'Супервайзер Айгуль',
                'email'      => 'supervisor@school.kg',
                'password'   => Hash::make('password'),
                'role'       => 'supervisor',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id'         => $teacherId,
                'school_id'  => $schoolId,
                'name'       => 'Учитель Акмат',
                'email'      => 'teacher@school.kg',
                'password'   => Hash::make('password'),
                'role'       => 'teacher',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // ── Классы ───────────────────────────────────────────────
        $classroomIds = [];
        foreach (['10А', '10Б', '11А'] as $i => $name) {
            $id = Str::uuid();
            $classroomIds[] = $id;
            DB::table('classrooms')->insert([
                'id'            => $id,
                'school_id'     => $schoolId,
                'name'          => "Класс {$name}",
                'code'          => $name,
                'capacity'      => 25,
                'camera_config' => json_encode([
                    [
                        'id'        => 'cam_front',
                        'rtsp_url'  => "rtsp://192.168.1.10{$i}:554/stream",
                        'position'  => 'front',
                        'is_active' => true,
                    ],
                ]),
                'is_active'     => true,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }

        // ── Студенты (20 студентов в первом классе) ───────────────
        $names = [
            'Айбек Усупов', 'Айгерим Токтосунова', 'Алибек Джумабаев',
            'Анара Бекова', 'Асель Исакова', 'Бакыт Эшматов',
            'Гулназ Мамытова', 'Данияр Жунусов', 'Диана Касымова',
            'Жаныбек Орунбеков', 'Зарина Сыдыкова', 'Кайрат Абдиев',
            'Ландыш Тоторова', 'Мирлан Омуров', 'Нурлан Асанов',
            'Перизат Жакыпова', 'Рустем Байтиков', 'Салтанат Дуйшеева',
            'Тилек Молдобаев', 'Умут Акматова',
        ];

        foreach ($names as $i => $name) {
            $studentId = Str::uuid();
            DB::table('students')->insert([
                'id'            => $studentId,
                'school_id'     => $schoolId,
                'name'          => $name,
                'student_code'  => sprintf('S%04d', $i + 1),
                'consent_given' => true,
                'consent_given_at' => now(),
                'is_active'     => true,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

            DB::table('classroom_student')->insert([
                'id'           => Str::uuid(),
                'classroom_id' => $classroomIds[0],
                'student_id'   => $studentId,
                'seat_number'  => $i + 1,
                'enrolled_at'  => now(),
            ]);
        }

        // ── Пороги алертов ────────────────────────────────────────
        DB::table('alert_thresholds')->insert([
            'id'                             => Str::uuid(),
            'school_id'                      => $schoolId,
            'classroom_id'                   => null,
            'low_class_threshold'            => 50.00,
            'low_student_threshold'          => 30.00,
            'absent_minutes_threshold'       => 3,
            'prolonged_low_minutes'          => 10,
            'rapid_decline_delta'            => 25.00,
            'rapid_decline_window_seconds'   => 60,
            'notify_supervisor'              => true,
            'notify_teacher'                 => true,
            'sound_alert'                    => false,
            'created_at'                     => now(),
            'updated_at'                     => now(),
        ]);

        $this->command->info('Seeding завершён: 1 школа, 3 класса, 20 студентов, 3 пользователя');
    }
}
