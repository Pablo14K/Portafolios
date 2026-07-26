package com.example.jahechacine.ui;

import android.content.Intent;
import android.os.Bundle;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.ProgressBar;
import android.widget.TextView;

import androidx.annotation.NonNull;
import androidx.annotation.Nullable;
import androidx.fragment.app.Fragment;
import androidx.recyclerview.widget.GridLayoutManager;
import androidx.recyclerview.widget.RecyclerView;

import com.example.jahechacine.R;
import com.example.jahechacine.api.TmdbClient;
import com.example.jahechacine.model.MediaItem;
import com.example.jahechacine.model.TmdbResponse;

import java.util.ArrayList;
import java.util.List;

import retrofit2.Call;
import retrofit2.Callback;
import retrofit2.Response;

public class HomeFragment extends Fragment {

    private MediaAdapter adapter;
    private final List<MediaItem> mediaList = new ArrayList<>();
    private ProgressBar progressBar;
    private TextView errorText;
    private String type = "all";

    public static HomeFragment newInstance(String type) {
        HomeFragment fragment = new HomeFragment();
        Bundle args = new Bundle();
        args.putString("type", type);
        fragment.setArguments(args);
        return fragment;
    }

    @Override
    public void onCreate(@Nullable Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        if (getArguments() != null) {
            type = getArguments().getString("type", "all");
        }
    }

    @Nullable
    @Override
    public View onCreateView(@NonNull LayoutInflater inflater, @Nullable ViewGroup container, @Nullable Bundle savedInstanceState) {
        View view = inflater.inflate(R.layout.fragment_home, container, false);

        RecyclerView recyclerView = view.findViewById(R.id.recycler_view);
        progressBar = view.findViewById(R.id.progress_bar);
        errorText = view.findViewById(R.id.error_text);

        recyclerView.setLayoutManager(new GridLayoutManager(getContext(), 2));
        adapter = new MediaAdapter(mediaList, item -> {
            Intent intent = new Intent(getActivity(), DetailActivity.class);
            intent.putExtra("media", item);
            startActivity(intent);
        });
        recyclerView.setAdapter(adapter);

        loadData();

        return view;
    }

    private void loadData() {
        progressBar.setVisibility(View.VISIBLE);
        errorText.setVisibility(View.GONE);
        
        Call<TmdbResponse<MediaItem>> call;
        if ("movies".equals(type)) {
            call = TmdbClient.getApiService().getPopularMovies();
        } else if ("series".equals(type)) {
            call = TmdbClient.getApiService().getPopularSeries();
        } else {
            call = TmdbClient.getApiService().getTrending();
        }

        call.enqueue(new Callback<TmdbResponse<MediaItem>>() {
            @Override
            public void onResponse(@NonNull Call<TmdbResponse<MediaItem>> call, @NonNull Response<TmdbResponse<MediaItem>> response) {
                progressBar.setVisibility(View.GONE);
                if (response.isSuccessful() && response.body() != null) {
                    mediaList.clear();
                    List<MediaItem> results = response.body().getResults();
                    // Manually set media type if not present (trending has it, popular might not)
                    for (MediaItem item : results) {
                        if (item.getMediaType() == null) {
                            item.setMediaType("movies".equals(type) ? "movie" : "tv");
                        }
                    }
                    mediaList.addAll(results);
                    adapter.notifyDataSetChanged();
                } else {
                    showError("Error: " + response.code());
                }
            }

            @Override
            public void onFailure(@NonNull Call<TmdbResponse<MediaItem>> call, @NonNull Throwable t) {
                progressBar.setVisibility(View.GONE);
                showError("Error de red: " + t.getMessage());
            }
        });
    }

    private void showError(String message) {
        errorText.setVisibility(View.VISIBLE);
        errorText.setText(message);
    }
}