"""IoU-tracker для устойчивого ID студентов между кадрами.

Простая реализация (без эмбеддингов): сопоставляем bbox этого кадра
с предыдущими по IoU + центрам, ID живёт `max_age` пропусков.

По одному инстансу на сессию. Студенты — фиксированный список для
сессии, привязка bbox→student_id строится на старте (или fallback —
сортировка слева-направо первого "хорошего" кадра).
"""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Dict, List, Optional, Tuple


def iou(a: Tuple[int, int, int, int], b: Tuple[int, int, int, int]) -> float:
    """IoU двух bbox в формате (x, y, w, h)."""
    ax, ay, aw, ah = a
    bx, by, bw, bh = b
    ax2, ay2 = ax + aw, ay + ah
    bx2, by2 = bx + bw, by + bh
    ix1, iy1 = max(ax, bx), max(ay, by)
    ix2, iy2 = min(ax2, bx2), min(ay2, by2)
    if ix2 <= ix1 or iy2 <= iy1:
        return 0.0
    inter = (ix2 - ix1) * (iy2 - iy1)
    union = aw * ah + bw * bh - inter
    return inter / union if union > 0 else 0.0


@dataclass
class Track:
    """Один трекаемый студент."""

    student_id: str
    bbox: Tuple[int, int, int, int]
    misses: int = 0  # сколько подряд кадров не виден
    history: List[Tuple[int, int]] = field(default_factory=list)  # центры


class StudentTracker:
    """Сопоставляет bbox каждого нового кадра со student_id.

    Алгоритм:
    1. На первом кадре — если есть `student_ids` и нет привязки, делаем
       сортировку слева-направо и привязываем по индексу (fallback).
    2. На последующих — для каждого нового bbox ищем существующий трек
       с максимальным IoU >= `min_iou`; если найден — обновляем bbox.
    3. Bbox без матча — пытаемся сопоставить с пропавшими треками
       (misses < max_age) по близости центра.
    4. Треки без матча в текущем кадре — увеличиваем misses; если
       misses > max_age — удаляем.
    """

    def __init__(self, student_ids: List[str], min_iou: float = 0.3, max_age: int = 5) -> None:
        self.student_ids = list(student_ids)
        self.min_iou = min_iou
        self.max_age = max_age
        self.tracks: Dict[str, Track] = {}
        self._initialized = False

    def update(self, bboxes: List[Tuple[int, int, int, int]]) -> Dict[str, Tuple[int, int, int, int]]:
        """Принимает список bbox этого кадра, возвращает {student_id: bbox}.

        Если для какого-то student_id нет матча в этом кадре — он просто
        отсутствует в результате (вызывающий код пометит как face_detected=False).
        """
        if not bboxes:
            self._age_unmatched_tracks(matched_ids=set())
            return {}

        if not self._initialized:
            return self._initialize(bboxes)

        # Жадный матчинг по IoU
        result: Dict[str, Tuple[int, int, int, int]] = {}
        used_bbox_idx: set[int] = set()
        for sid, track in self.tracks.items():
            best_iou = 0.0
            best_idx = -1
            for i, bb in enumerate(bboxes):
                if i in used_bbox_idx:
                    continue
                score = iou(track.bbox, bb)
                if score > best_iou:
                    best_iou = score
                    best_idx = i
            if best_idx >= 0 and best_iou >= self.min_iou:
                track.bbox = bboxes[best_idx]
                track.misses = 0
                track.history.append(_center(bboxes[best_idx]))
                track.history = track.history[-30:]
                used_bbox_idx.add(best_idx)
                result[sid] = bboxes[best_idx]

        # Незаматченные bbox — пытаемся отдать пропавшим трекам по близости центров
        for i, bb in enumerate(bboxes):
            if i in used_bbox_idx:
                continue
            cx, cy = _center(bb)
            best_sid: Optional[str] = None
            best_dist = float("inf")
            for sid, track in self.tracks.items():
                if sid in result:
                    continue
                if track.misses == 0:
                    continue
                lx, ly = _center(track.bbox)
                dist = ((cx - lx) ** 2 + (cy - ly) ** 2) ** 0.5
                if dist < best_dist:
                    best_dist = dist
                    best_sid = sid
            if best_sid is not None and best_dist < max(bb[2], bb[3]) * 1.5:
                self.tracks[best_sid].bbox = bb
                self.tracks[best_sid].misses = 0
                self.tracks[best_sid].history.append((cx, cy))
                self.tracks[best_sid].history = self.tracks[best_sid].history[-30:]
                used_bbox_idx.add(i)
                result[best_sid] = bb

        self._age_unmatched_tracks(matched_ids=set(result.keys()))
        return result

    # ── internals ────────────────────────────────────────────────

    def _initialize(self, bboxes: List[Tuple[int, int, int, int]]) -> Dict[str, Tuple[int, int, int, int]]:
        # Сортировка слева-направо + привязка к student_ids по индексу
        ordered = sorted(enumerate(bboxes), key=lambda kv: _center(kv[1])[0])
        result: Dict[str, Tuple[int, int, int, int]] = {}
        for j, sid in enumerate(self.student_ids):
            if j >= len(ordered):
                break
            _, bb = ordered[j]
            self.tracks[sid] = Track(student_id=sid, bbox=bb, history=[_center(bb)])
            result[sid] = bb
        self._initialized = True
        return result

    def _age_unmatched_tracks(self, matched_ids: set[str]) -> None:
        for sid, track in list(self.tracks.items()):
            if sid not in matched_ids:
                track.misses += 1
                if track.misses > self.max_age:
                    # удаляем — пусть на следующем "хорошем" кадре
                    # инициализация переоткроет трек, если кадр ок
                    self.tracks.pop(sid, None)


def _center(bb: Tuple[int, int, int, int]) -> Tuple[int, int]:
    return bb[0] + bb[2] // 2, bb[1] + bb[3] // 2
