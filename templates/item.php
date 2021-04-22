<?php if ($item['level'] > 1): ?>
    <a href="<?= htmlspecialchars($item['href']) ?>" <?php if ($item['active']): ?>class="is-active"<?php endif ?>>
        <?= $item['text'] ?>
    </a>
<?php endif ?>

<?php if ($item['children']): ?>
<ul class="sidebar-level-<?= $item['level'] ?>">
<?php foreach ($item['children'] as $child): ?>
    <li id="<?= $child['id'] ?>">
        <?php $this->renderItem($child) ?>
    </li>
<?php endforeach ?>
</ul>
<?php endif ?>
