package com.example.jahechacine.ui;

import android.os.Bundle;
import android.view.View;
import android.webkit.WebChromeClient;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.ProgressBar;

import androidx.appcompat.app.AppCompatActivity;

import com.example.jahechacine.R;

public class PlayerActivity extends AppCompatActivity {

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_player);

        int id = getIntent().getIntExtra("id", 0);
        String type = getIntent().getStringExtra("type");
        if (type == null) type = "movie";
        
        WebView webView = findViewById(R.id.player_webview);
        ProgressBar progressBar = findViewById(R.id.player_progress);

        WebSettings settings = webView.getSettings();
        settings.setJavaScriptEnabled(true);
        settings.setDomStorageEnabled(true);

        webView.setWebViewClient(new WebViewClient());
        webView.setWebChromeClient(new WebChromeClient() {
            @Override
            public void onProgressChanged(WebView view, int newProgress) {
                if (newProgress == 100) {
                    progressBar.setVisibility(View.GONE);
                }
            }
        });

        // Resolve correct embed URL for movies or TV series
        String endpoint = "movie".equals(type) ? "movie" : "tv";
        String playerUrl = "https://vidsrc.me/embed/" + endpoint + "?tmdb=" + id;
        
        webView.loadUrl(playerUrl);
    }

    @Override
    protected void onPause() {
        super.onPause();
        WebView webView = findViewById(R.id.player_webview);
        if (webView != null) {
            webView.onPause();
            webView.pauseTimers();
        }
    }

    @Override
    protected void onResume() {
        super.onResume();
        WebView webView = findViewById(R.id.player_webview);
        if (webView != null) {
            webView.onResume();
            webView.resumeTimers();
        }
    }
}