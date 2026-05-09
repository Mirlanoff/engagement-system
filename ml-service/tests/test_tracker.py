"""Тесты IoU-трекера."""

from app.ml.tracker import StudentTracker, iou


def test_iou_identical_boxes_is_one():
    assert iou((0, 0, 10, 10), (0, 0, 10, 10)) == 1.0


def test_iou_disjoint_is_zero():
    assert iou((0, 0, 10, 10), (100, 100, 10, 10)) == 0.0


def test_iou_partial_overlap():
    # 10x10 box vs 5px-shifted same-size box -> 5x5 intersection / (100+100-25)
    score = iou((0, 0, 10, 10), (5, 5, 10, 10))
    assert 0.1 < score < 0.2


def test_tracker_initializes_left_to_right():
    t = StudentTracker(["s1", "s2", "s3"])
    # Намеренно подаём в неправильном порядке
    r = t.update([(100, 50, 50, 50), (10, 40, 50, 50), (200, 60, 50, 50)])
    assert r["s1"][0] == 10   # самый левый — s1
    assert r["s2"][0] == 100
    assert r["s3"][0] == 200


def test_tracker_keeps_id_after_movement():
    t = StudentTracker(["s1", "s2", "s3"])
    t.update([(10, 40, 50, 50), (100, 50, 50, 50), (200, 60, 50, 50)])
    # Студенты слегка двигаются
    r = t.update([(15, 42, 50, 50), (105, 52, 50, 50), (205, 58, 50, 50)])
    assert r["s1"][0] == 15    # сместился чуть, но это всё ещё s1
    assert r["s2"][0] == 105
    assert r["s3"][0] == 205


def test_tracker_tolerates_short_miss():
    t = StudentTracker(["s1", "s2"], max_age=5)
    t.update([(10, 40, 50, 50), (100, 50, 50, 50)])
    # Один кадр s2 пропал
    t.update([(12, 42, 50, 50)])
    # Появился рядом — должны вернуть ему s2, не s1
    r = t.update([(15, 42, 50, 50), (105, 52, 50, 50)])
    assert "s1" in r and "s2" in r
    assert r["s1"][0] == 15
    assert r["s2"][0] == 105


def test_tracker_returns_partial_when_fewer_faces():
    t = StudentTracker(["s1", "s2", "s3"])
    r = t.update([(10, 40, 50, 50), (100, 50, 50, 50)])
    assert "s3" not in r
    assert len(r) == 2
