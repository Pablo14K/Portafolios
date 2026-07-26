package com.example.jahechacine.ui;

import android.content.Intent;
import android.os.Bundle;
import android.widget.Button;
import android.widget.ImageView;
import android.widget.TextView;

import androidx.appcompat.app.AppCompatActivity;

import com.bumptech.glide.Glide;
import com.example.jahechacine.R;
import com.example.jahechacine.model.MediaItem;

public class DetailActivity extends AppCompatActivity {

    private MediaItem item;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_detail);

        item = (MediaItem) getIntent().getSerializableExtra("media");

        ImageView backdrop = findViewById(R.id.detail_backdrop);
        TextView title = findViewById(R.id.detail_title);
        TextView overview = findViewById(R.id.detail_overview);
        Button btnPlay = findViewById(R.id.btn_play);

        if (item != null) {
            title.setText(item.getTitle());
            overview.setText(item.getOverview());
            Glide.with(this).load(item.getBackdropPath()).into(backdrop);
        }

        btnPlay.setOnClickListener(v -> {
            Intent intent = new Intent(DetailActivity.this, PlayerActivity.class);
            intent.putExtra("id", item.getId());
            intent.putExtra("type", item.getMediaType());
            startActivity(intent);
        });
    }
}