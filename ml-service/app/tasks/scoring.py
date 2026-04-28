from app.worker import celery_app


@celery_app.task(name="app.tasks.scoring.aggregate_scores", queue="scoring")
def aggregate_scores(session_id: str):
    """Агрегация scores за минуту — вызывается по расписанию."""
    pass
