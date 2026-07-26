package com.example.jahechacine.ui;

import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.ImageView;
import android.widget.TextView;

import androidx.annotation.NonNull;
import androidx.recyclerview.widget.RecyclerView;

import com.bumptech.glide.Glide;
import com.example.jahechacine.R;
import com.example.jahechacine.model.MediaItem;

import java.util.List;

public class MediaAdapter extends RecyclerView.Adapter<MediaAdapter.ViewHolder> {

    private final List<MediaItem> items;
    private final OnItemClickListener listener;

    public interface OnItemClickListener {
        void onItemClick(MediaItem item);
    }

    public MediaAdapter(List<MediaItem> items, OnItemClickListener listener) {
        this.items = items;
        this.listener = listener;
    }

    @NonNull
    @Override
    public ViewHolder onCreateViewHolder(@NonNull ViewGroup parent, int viewType) {
        View view = LayoutInflater.from(parent.getContext()).inflate(R.layout.item_movie, parent, false);
        return new ViewHolder(view);
    }

    @Override
    public void onBindViewHolder(@NonNull ViewHolder holder, int position) {
        MediaItem item = items.get(position);
        holder.title.setText(item.getTitle());
        Glide.with(holder.itemView.getContext())
                .load(item.getPosterPath())
                .placeholder(android.R.drawable.ic_menu_gallery)
                .into(holder.poster);

        holder.itemView.setOnClickListener(v -> listener.onItemClick(item));
    }

    @Override
    public int getItemCount() {
        return items.size();
    }

    public static class ViewHolder extends RecyclerView.ViewHolder {
        ImageView poster;
        TextView title;

        public ViewHolder(@NonNull View itemView) {
            super(itemView);
            poster = itemView.findViewById(R.id.movie_poster);
            title = itemView.findViewById(R.id.movie_title);
        }
    }
}