"""Versioned scene blueprints; independent of animation, evidence and delivery."""

SCENES_PER_CHAPTER = 6


def blueprint(game_id):
    region = {'enshrouded': 'mountains', 'aska': 'woodland', 'raft': 'coast'}.get(game_id, 'meadow')
    return 'landscape-v1-' + region


def scene_view(row):
    # Legacy rows keep their stored template. Their first revision is immutable,
    # so later corrections cannot silently change an existing illustration.
    template = row['template']
    illustration = blueprint(row['original_game']) if template == 'milestone' else template
    position = row['position']
    return {'id': row['scene_id'], 'position': position, 'template': template,
            'blueprint': illustration, 'chapter': position // SCENES_PER_CHAPTER,
            'slot': position % SCENES_PER_CHAPTER}
